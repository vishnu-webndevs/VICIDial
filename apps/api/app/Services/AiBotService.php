<?php

namespace App\Services;

use App\Models\AiBotAgent;
use App\Models\AiBotInteractiveFlow;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\Tenant;
use App\Models\TenantAiSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiBotService
{
    /**
     * Process inbound WhatsApp message safely for AI Auto-Pilot response.
     */
    public static function processInboundMessage(string $tenantId, MessageThread $thread, Message $inboundMsg): void
    {
        try {
            // Check if thread bot status is paused (human handoff)
            if ($thread->bot_status === 'paused' || $thread->bot_status === 'human_assigned') {
                return;
            }

            // Strict Rule: AI Bot MUST ONLY reply if the thread belongs to an assigned AI campaign.
            // If the customer is NOT in a campaign with an AI agent, DO NOT reply at all.
            $botAgent = null;

            // 1. Check if thread already has an active AI Bot assigned
            if ($thread->ai_bot_agent_id) {
                $botAgent = AiBotAgent::query()
                    ->where('tenant_id', $tenantId)
                    ->where('id', $thread->ai_bot_agent_id)
                    ->where('is_active', true)
                    ->first();
            }

            // 2. If not yet set on thread, check if any outbound message in this thread was from an AI campaign
            if (!$botAgent) {
                $campaignId = Message::query()
                    ->where('tenant_id', $tenantId)
                    ->where('thread_id', $thread->id)
                    ->where('direction', 'outbound')
                    ->whereNotNull('metadata->campaign_id')
                    ->latest('sent_at')
                    ->value('metadata->campaign_id');

                if ($campaignId) {
                    $campaign = \App\Models\Campaign::query()
                        ->where('tenant_id', $tenantId)
                        ->where('id', $campaignId)
                        ->first();
                    if ($campaign && !empty($campaign->ai_bot_agent_id)) {
                        $botAgent = AiBotAgent::query()
                            ->where('tenant_id', $tenantId)
                            ->where('id', $campaign->ai_bot_agent_id)
                            ->where('is_active', true)
                            ->first();

                        if ($botAgent) {
                            $thread->update([
                                'ai_bot_agent_id' => $botAgent->id,
                            ]);
                        }
                    }
                }
            }

            // 3. Check if lead with this phone number was targeted by an active AI campaign
            if (!$botAgent) {
                $cleanNumber = preg_replace('/[^0-9]/', '', (string) $thread->counterparty_number);
                $campaignId = DB::table('leads')
                    ->join('lead_timeline_items', 'leads.id', '=', 'lead_timeline_items.lead_id')
                    ->where('leads.tenant_id', $tenantId)
                    ->where(function ($q) use ($thread, $cleanNumber) {
                        $q->where('leads.phone', $thread->counterparty_number)
                          ->orWhereRaw("REGEXP_REPLACE(leads.phone, '[^0-9]', '') = ?", [$cleanNumber]);
                    })
                    ->whereNotNull('lead_timeline_items.metadata->campaign_id')
                    ->latest('lead_timeline_items.occurred_at')
                    ->value('lead_timeline_items.metadata->campaign_id');

                if ($campaignId) {
                    $campaign = \App\Models\Campaign::query()
                        ->where('tenant_id', $tenantId)
                        ->where('id', $campaignId)
                        ->first();
                    if ($campaign && !empty($campaign->ai_bot_agent_id)) {
                        $botAgent = AiBotAgent::query()
                            ->where('tenant_id', $tenantId)
                            ->where('id', $campaign->ai_bot_agent_id)
                            ->where('is_active', true)
                            ->first();

                        if ($botAgent) {
                            $thread->update([
                                'ai_bot_agent_id' => $botAgent->id,
                            ]);
                        }
                    }
                }
            }

            // STRICT: If this customer/thread is NOT part of a campaign with an AI agent assigned, DO NOT REPLY!
            if (!$botAgent) {
                Log::info("AiBotService: Inbound message from {$thread->counterparty_number} skipped — number is not part of any AI campaign.", [
                    'tenant_id' => $tenantId,
                    'thread_id' => $thread->id,
                ]);
                return;
            }

            $userText = trim((string) $inboundMsg->body);
            if ($userText === '') {
                return;
            }

            // 1. Check for Interactive Button Flow Trigger (Exact or Keyword match)
            $cleanUser = strtolower($userText);
            $flow = AiBotInteractiveFlow::query()
                ->where('ai_bot_agent_id', $botAgent->id)
                ->where(function ($q) use ($cleanUser) {
                    $q->whereRaw('LOWER(trigger_keyword) = ?', [$cleanUser])
                      ->orWhereRaw('? LIKE CONCAT("%", LOWER(trigger_keyword), "%")', [$cleanUser]);
                })
                ->first();

            if ($flow) {
                self::sendInteractiveFlowResponse($tenantId, $thread, $flow, $botAgent);
                return;
            }

            // 2. Generate AI Response using Gemini API
            self::generateAndSendAiResponse($tenantId, $thread, $botAgent, $userText);

        } catch (\Throwable $e) {
            Log::error('AiBotService Error: ' . $e->getMessage(), [
                'tenant_id' => $tenantId,
                'thread_id' => $thread->id,
                'exception' => $e,
            ]);
        }
    }

    /**
     * Send Interactive Flow Response (Button Menu)
     */
    private static function sendInteractiveFlowResponse(string $tenantId, MessageThread $thread, AiBotInteractiveFlow $flow, AiBotAgent $bot): void
    {
        $options = (array) ($flow->options ?? []);
        $questionText = $flow->question_text;

        // Simulate natural human typing delay (2-3 sec)
        sleep(min($bot->human_delay_seconds ?? 3, 5));

        // Format message body with clear options list if WhatsApp interactive payload or plain text
        $body = $questionText;
        if (!empty($options)) {
            $body .= "\n\n" . implode(" | ", array_map(fn($opt) => "👉 " . $opt, $options));
        }

        // Dispatch outbound message through WhatsApp provider
        self::dispatchOutboundMessage($tenantId, $thread, $body, [
            'flow_id' => $flow->id,
            'bot_agent_id' => $bot->id,
        ]);
    }

    /**
     * Call Gemini API with Human Persona System Prompt & Knowledge Base Context
     */
    private static function generateAndSendAiResponse(string $tenantId, MessageThread $thread, AiBotAgent $botAgent, string $userText): void
    {
        // Fetch Tenant AI Setting & Resolve API Keys (OpenAI Primary, Gemini Fallback)
        $aiSetting = TenantAiSetting::query()->where('tenant_id', $tenantId)->first();
        $configuredProvider = strtolower((string) ($aiSetting?->provider ?? ''));
        $tenantApiKey = trim((string) ($aiSetting?->api_key ?? ''));

        $openAiKey = null;
        if ($configuredProvider === 'openai' && !empty($tenantApiKey)) {
            $openAiKey = $tenantApiKey;
        } elseif (!empty($tenantApiKey) && str_starts_with($tenantApiKey, 'sk-')) {
            $openAiKey = $tenantApiKey;
        } else {
            $openAiKey = env('OPENAI_API_KEY') ?: null;
        }

        $geminiKey = null;
        if ($configuredProvider === 'gemini' && !empty($tenantApiKey)) {
            $geminiKey = $tenantApiKey;
        } elseif (!empty($tenantApiKey) && !str_starts_with($tenantApiKey, 'sk-')) {
            $geminiKey = $tenantApiKey;
        } else {
            $geminiKey = env('GEMINI_API_KEY') ?: null;
        }

        if (empty($openAiKey) && empty($geminiKey)) {
            Log::warning("AiBotService: Neither OpenAI nor Gemini API key found for tenant {$tenantId}");
            return;
        }

        // Fetch recent conversation history & inspect for previous fallbacks
        $recentMessages = Message::query()
            ->where('thread_id', $thread->id)
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get()
            ->reverse();

        $lastOutboundBody = null;
        $fallbackSentInHistory = false;
        $fallbackMessage = trim((string) ($botAgent->fallback_message ?? ''));

        foreach ($recentMessages as $msg) {
            if ($msg->direction === 'outbound') {
                $lastOutboundBody = (string) $msg->body;
                $lowerBody = strtolower($msg->body);
                if (
                    str_contains($lowerBody, 'jankari') ||
                    str_contains($lowerBody, 'available nahi') ||
                    str_contains($lowerBody, 'mere paas abhi nahi') ||
                    ($fallbackMessage !== '' && str_contains($lowerBody, strtolower(substr($fallbackMessage, 0, 15))))
                ) {
                    $fallbackSentInHistory = true;
                }
            }
        }

        $agentName = trim((string) ($botAgent->name ?? ''));
        $agentDescription = trim((string) ($botAgent->description ?? ''));
        $instructionsText = trim((string) ($botAgent->system_instructions ?? ''));
        $customKnowledgeText = trim((string) ($botAgent->custom_knowledge_prompt ?? ''));
        $privacyPolicyText = trim((string) ($botAgent->privacy_policy ?? ''));
        $isStrictKb = (bool) ($botAgent->strict_mode ?? $botAgent->is_strict_kb ?? false);

        $kbData = is_array($botAgent->knowledge_base) ? json_encode($botAgent->knowledge_base, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : (string) $botAgent->knowledge_base;

        $tenantObj = Tenant::find($tenantId);
        $companyName = $tenantObj?->name ?: '';

        // Construct 100% PURE DYNAMIC System Prompt strictly from fields configured in the AI Bot Agent Form
        $promptSections = [];

        if (!empty($agentName) || !empty($companyName) || !empty($agentDescription)) {
            $identity = "You are an autonomous AI Agent named '{$agentName}'" . ($companyName ? " representing {$companyName}." : ".");
            if (!empty($agentDescription)) {
                $identity .= "\n=== AGENT PURPOSE & PRIMARY OBJECTIVE ===\n" . $agentDescription;
            }
            $promptSections[] = $identity;
        }

        if ($isStrictKb) {
            $promptSections[] = "=== STRICT KNOWLEDGE BASE LOCK ===\n" .
                "1. All factual details (prices, availability, locations, specs, terms, policies) MUST come strictly from the Knowledge Base and Master Prompt below. Do NOT invent outside facts or hallucinate unapproved business information.\n" .
                "2. STRICT LOCK DEFINITION: Strict KB Lock prevents you from inventing fake business facts. It does NOT restrict your conversational reasoning, understanding intent, handling objections, or guiding customers using your configured purpose and knowledge!";
        }

        if (!empty($instructionsText)) {
            $promptSections[] = "=== AGENT PERSONA & INSTRUCTIONS ===\n" . $instructionsText;
        }

        if (!empty($customKnowledgeText)) {
            $promptSections[] = "=== MASTER BUSINESS KNOWLEDGE & PROMPT ===\n" . $customKnowledgeText;
        }

        if (!empty($kbData) && $kbData !== '[]' && $kbData !== 'null') {
            $promptSections[] = "=== AUTHORITATIVE BUSINESS KNOWLEDGE BASE ===\n" . $kbData;
        }

        if (!empty($privacyPolicyText)) {
            $promptSections[] = "=== PRIVACY & SECURITY RULES ===\n" . $privacyPolicyText;
        }

        // Add Enforced Conversational & Intent Rules
        $activeFallbackString = $fallbackMessage ?: "Ji, iski exact jankari mere paas abhi nahi hai.";
        $promptSections[] = <<<RULES
=== AUTONOMOUS CONVERSATIONAL REASONING & RULES ===

1. AUTONOMOUS AGENT REASONING & INTENT UNDERSTANDING:
   - You are a true business AI Agent, NOT a rigid FAQ lookup bot.
   - Synthesize your AGENT PURPOSE, SYSTEM INSTRUCTIONS, and BUSINESS KNOWLEDGE to determine the best response for the customer's actual intent and situation.
   - Do NOT expect exact keyword or FAQ matches from the customer. Understand intent, Hinglish, synonyms, casual phrasing, and customer context:
     * Open-ended requests ("bhai kuch accha sa dikhao", "details batao", "kuch dikhao"): Understand customer interest and continue the conversation using your knowledge base and agent purpose.
     * Objections & concerns ("budget thoda kam hai", "thoda mehnga lag raha hai"): Understand the customer's objection and respond helpfully according to your agent purpose and available options.
     * Conversational & decision updates ("mummy se puch ke batata hu", "kal baat karte hain"): Understand these as normal conversation steps and reply naturally in character.
     * Synonyms & locations ("kaha hai?", "address?", "where is it?"): Answer using your location info from knowledge.

2. CONVERSATIONAL MESSAGES vs OUT-OF-SCOPE FALLBACK:
   - Greetings ("hi", "hello", "hey", "namaste", "good morning"): Respond naturally and warmly in character. NEVER output fallback for greetings!
   - Acknowledgments ("okay", "ok", "haan", "han", "ji", "theek hai", "acha", "hmm", "thanks"): Respond naturally in character. NEVER output fallback for acknowledgments!
   - Emojis ("😂", "👍", "🙂", "😊", "❤️"): Respond warmly with a short friendly reaction. NEVER output fallback!
   - Buying / Service Interest: Respond warmly using your Knowledge Base / Master Prompt context and continue the conversation towards your agent's objective.

3. STRICT FACTUAL SAFETY (NO HALLUCINATIONS OR FAKE ACTIONS):
   - You MUST NOT invent business facts (prices, availability, location, amenities, specifications, legal/RERA info, policies, order/payment status, appointments).
   - NEVER claim that a system action occurred (e.g., "call scheduled", "manager will call you", "brochure sent to your WhatsApp", "site visit booked", "payment confirmed", "order placed") UNLESS a real backend action performed it.
   - Outbound voice calling is unavailable on WhatsApp. If customer asks for a call ("call kro", "call karo"), politely inform them in 1 short sentence that voice calling is unavailable here and you are happy to answer all questions right here in chat.

4. FALLBACK RULE (LAST RESORT ONLY):
   - ONLY send the out-of-scope fallback when the customer asks for a specific, unresolvable factual detail that genuinely does NOT exist anywhere in your configured Knowledge Base or Master Prompt.
   - Configured Fallback Message: "{$activeFallbackString}"
   - Never send the exact same fallback sentence repeatedly in the same conversation.

5. RESPONSE STYLE & BREVITY:
   - Keep responses short, natural, clear, and professional (1 to 2 short sentences).
   - Ask at most ONE useful question at a time to guide the customer. Do not produce long mechanical sales pitches.
RULES;

        if ($fallbackSentInHistory) {
            $promptSections[] = "CRITICAL NOTICE: A fallback message was ALREADY sent recently in this thread. If the customer repeats the unknown question, do NOT repeat the fallback sentence! Provide a short alternative response or ask how else you can assist.";
        }

        $systemPrompt = implode("\n\n", $promptSections);

        // Build cleanly alternating conversation history for Gemini API
        $contents = [];
        $lastRole = null;
        foreach ($recentMessages as $msg) {
            $role = $msg->direction === 'inbound' ? 'user' : 'model';
            $text = trim((string) $msg->body);
            if ($text === '') continue;

            if ($role === $lastRole) {
                // Merge consecutive messages of the same role to adhere to Gemini alternating turn rules
                $lastIndex = count($contents) - 1;
                $contents[$lastIndex]['parts'][0]['text'] .= "\n" . $text;
            } else {
                $contents[] = [
                    'role' => $role,
                    'parts' => [['text' => $text]],
                ];
                $lastRole = $role;
            }
        }

        // Gemini conversation must start with 'user'
        while (!empty($contents) && $contents[0]['role'] !== 'user') {
            array_shift($contents);
        }

        // Gemini conversation must end with 'user'
        if (empty($contents) || end($contents)['role'] !== 'user') {
            $contents[] = [
                'role' => 'user',
                'parts' => [['text' => $userText]],
            ];
        }

        // Build messages for OpenAI API
        $openAiMessages = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];
        foreach ($recentMessages as $msg) {
            $role = $msg->direction === 'inbound' ? 'user' : 'assistant';
            $text = trim((string) $msg->body);
            if ($text !== '') {
                $openAiMessages[] = [
                    'role' => $role,
                    'content' => $text,
                ];
            }
        }
        $lastOpenAi = end($openAiMessages);
        if (!$lastOpenAi || $lastOpenAi['role'] !== 'user' || $lastOpenAi['content'] !== $userText) {
            $openAiMessages[] = [
                'role' => 'user',
                'content' => $userText,
            ];
        }

        $configuredModel = $aiSetting?->default_model;
        $aiText = '';

        // 1. PRIMARY PROVIDER: Try OpenAI first if key is available
        if (!empty($openAiKey)) {
            $openAiModelToUse = ($configuredProvider === 'openai' && !empty($configuredModel)) ? $configuredModel : 'gpt-4o-mini';
            $openAiModels = array_filter(array_unique([
                $openAiModelToUse,
                'gpt-4o-mini',
                'gpt-4o',
                'gpt-3.5-turbo',
            ]));

            foreach ($openAiModels as $modelName) {
                try {
                    $response = Http::timeout(12)->withHeaders([
                        'Authorization' => 'Bearer ' . $openAiKey,
                        'Content-Type' => 'application/json',
                    ])->post('https://api.openai.com/v1/chat/completions', [
                        'model' => $modelName,
                        'messages' => $openAiMessages,
                        'temperature' => 0.6,
                        'max_tokens' => 1000,
                    ]);

                    if ($response->successful()) {
                        $responseData = $response->json();
                        $aiText = trim($responseData['choices'][0]['message']['content'] ?? '');
                        if ($aiText !== '') {
                            Log::info("AiBotService: Generated response via OpenAI ({$modelName}) for tenant {$tenantId}");
                            break;
                        }
                    } else {
                        Log::warning("AiBotService: OpenAI model {$modelName} failed (HTTP {$response->status()}) for tenant {$tenantId}: " . substr($response->body(), 0, 200));
                    }
                } catch (\Throwable $e) {
                    Log::warning("AiBotService: OpenAI request exception for model {$modelName} (tenant {$tenantId}): " . $e->getMessage());
                }
            }
        }

        // 2. SECONDARY PROVIDER: Try Gemini ONLY if OpenAI was not available or failed to produce a valid response
        if ($aiText === '' && !empty($geminiKey)) {
            if (!empty($openAiKey)) {
                Log::info("AiBotService: OpenAI provider unavailable/failed for tenant {$tenantId}. Falling back to Gemini provider...");
            }

            $geminiModelToUse = ($configuredProvider === 'gemini' && !empty($configuredModel) && $configuredModel !== 'gemini-flash-latest')
                ? $configuredModel
                : 'gemini-1.5-flash';

            $geminiModels = array_filter(array_unique([
                $geminiModelToUse,
                'gemini-1.5-flash',
                'gemini-2.0-flash',
                'gemini-1.5-flash-latest',
                'gemini-1.5-pro',
            ]));

            foreach ($geminiModels as $modelName) {
                $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$modelName}:generateContent?key={$geminiKey}";
                try {
                    $response = Http::timeout(12)->withHeaders(['Content-Type' => 'application/json'])
                        ->post($endpoint, [
                            'system_instruction' => [
                                'parts' => [['text' => $systemPrompt]]
                            ],
                            'contents' => $contents,
                            'generationConfig' => [
                                'temperature' => 0.6,
                                'maxOutputTokens' => 1000,
                            ],
                        ]);

                    if ($response->successful()) {
                        $responseData = $response->json();
                        $aiText = trim($responseData['candidates'][0]['content']['parts'][0]['text'] ?? '');
                        if ($aiText !== '') {
                            Log::info("AiBotService: Generated response via Gemini ({$modelName}) for tenant {$tenantId}");
                            break;
                        }
                    } else {
                        Log::warning("AiBotService: Gemini model {$modelName} failed (HTTP {$response->status()}) for tenant {$tenantId}: " . substr($response->body(), 0, 200));
                    }
                } catch (\Throwable $e) {
                    Log::warning("AiBotService: Gemini request exception for model {$modelName} (tenant {$tenantId}): " . $e->getMessage());
                }
            }
        }

        if ($aiText === '') {
            Log::warning("AiBotService: AI generation unavailable for tenant {$tenantId}. Applying smart intent fallback...");
            $trimmedUser = trim($userText);
            $userLower = strtolower($trimmedUser);

            // 1. Detect Greetings
            $greetings = ['hi', 'hello', 'hey', 'hii', 'namaste', 'good morning', 'good afternoon', 'good evening', 'hlo'];
            $isGreeting = in_array($userLower, $greetings, true) || preg_match('/^(hi|hello|hey|namaste)\b/i', $userLower);

            // 2. Detect Acknowledgments & Casual
            $acks = ['ok', 'okay', 'haan', 'han', 'ji', 'yes', 'sure', 'theek hai', 'thik hai', 'acha', 'accha', 'hmm', 'hmmm', 'got it', 'right', 'thanks', 'thank you'];
            $isAck = in_array($userLower, $acks, true) || str_contains($userLower, 'maine sirf hi') || str_contains($userLower, 'arey');

            // 3. Detect Emoji-only
            $isEmoji = (preg_match('/^[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{2600}-\x{26FF}\x{1F900}-\x{1F9FF}\x{1F1E6}-\x{1F1FF}\s]+$/u', $trimmedUser) === 1);

            // 4. Detect Buying/Service Intent / Detail Requests
            $intentKeywords = ['dekhna', 'chahiye', 'buy', 'order', 'detail', 'details', 'jankari', 'info', 'information', 'service', 'baat'];
            $isIntent = false;
            foreach ($intentKeywords as $kw) {
                if (str_contains($userLower, $kw)) {
                    $isIntent = true;
                    break;
                }
            }

            if ($isGreeting) {
                $aiText = "Hello ji! Main " . ($companyName ? "{$companyName} se " : "") . "aapki kya madad kar sakta hoon?";
            } elseif ($isAck) {
                $aiText = "Ji, bataiye aapko kis detail ke baare me jan-na hai?";
            } elseif ($isEmoji) {
                $aiText = "😊";
            } elseif ($isIntent) {
                // If Knowledge Base has entries, offer help using agent knowledge context
                $kbArray = is_array($botAgent->knowledge_base) ? $botAgent->knowledge_base : json_decode((string)$botAgent->knowledge_base, true);
                if (is_array($kbArray) && !empty($kbArray)) {
                    $firstAnswer = reset($kbArray)['answer'] ?? '';
                    if ($firstAnswer !== '') {
                        $aiText = "Ji, " . (mb_strlen($firstAnswer) > 100 ? mb_substr($firstAnswer, 0, 100) . '...' : $firstAnswer);
                    }
                }
                if ($aiText === '') {
                    $aiText = "Ji! Main " . ($companyName ? "{$companyName} se " : "") . "aapki bilkul madad kar sakta hoon. Aapko kya detail chahiye?";
                }
            } else {
                // Search Knowledge Base Q&A Array (Exact or substring)
                $kbArray = is_array($botAgent->knowledge_base) ? $botAgent->knowledge_base : json_decode((string)$botAgent->knowledge_base, true);
                if (is_array($kbArray)) {
                    foreach ($kbArray as $qa) {
                        $qLower = strtolower($qa['question'] ?? '');
                        if ($qLower !== '' && (str_contains($userLower, $qLower) || str_contains($qLower, $userLower))) {
                            $aiText = (string) ($qa['answer'] ?? '');
                            break;
                        }
                    }
                }

                // If still empty, handle out-of-scope fallback with anti-repetition
                if ($aiText === '') {
                    $activeFallback = $fallbackMessage ?: "Ji, iski exact jankari mere paas abhi nahi hai.";
                    if ($fallbackSentInHistory || ($lastOutboundBody && str_contains(strtolower($lastOutboundBody), 'jankari'))) {
                        $aiText = "Ji, ye detail abhi available nahi hai.";
                    } else {
                        $aiText = $activeFallback;
                    }
                }
            }
        }

        // Simulate natural human typing delay (2-4 seconds)
        sleep(min($botAgent->human_delay_seconds ?? 3, 5));

        // Dispatch outbound AI message to provider (Meta WhatsApp / Twilio)
        self::dispatchOutboundMessage($tenantId, $thread, $aiText, [
            'bot_agent_id' => $botAgent->id,
        ]);
    }

    /**
     * Dispatch outbound WhatsApp / SMS message via active provider (Meta WhatsApp / Twilio)
     */
    private static function dispatchOutboundMessage(string $tenantId, MessageThread $thread, string $body, array $metadata = []): ?Message
    {
        try {
            $providerTypes = $thread->channel === 'whatsapp' ? ['meta_whatsapp', 'twilio'] : ['twilio'];
            $provider = \App\Models\ProviderAccount::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('provider_type', $providerTypes)
                ->where('status', 'active')
                ->orderByRaw("CASE WHEN provider_type = 'meta_whatsapp' THEN 1 ELSE 2 END")
                ->latest('created_at')
                ->first();

            $providerCredentials = (array) ($provider?->credentials_encrypted ?? []);
            $statusCallbackUrl = rtrim((string) config('app.url'), '/').'/api/v1/webhooks/twilio/message-status';

            $result = $thread->channel === 'sms'
                ? app(\App\Services\Messaging\SmsService::class)->send((string) $thread->counterparty_number, $body, $statusCallbackUrl, $providerCredentials)
                : app(\App\Services\Messaging\WhatsAppService::class)->send((string) $thread->counterparty_number, $body, $statusCallbackUrl, $providerCredentials);

            $status = 'sent';
            $providerMessageId = null;

            if (($result['ok'] ?? false) === true) {
                $providerMessageId = (string) ($result['provider_message_id'] ?? '');
                $status = (string) ($result['status'] ?? 'queued');
            } else {
                Log::error('AiBotService Outbound Network Dispatch Failed: ' . ($result['error'] ?? 'Unknown error'));
            }

            $message = Message::create([
                'tenant_id' => $tenantId,
                'thread_id' => $thread->id,
                'direction' => 'outbound',
                'status' => $status,
                'body' => $body,
                'provider_message_id' => $providerMessageId,
                'metadata' => array_merge([
                    'channel' => $thread->channel,
                    'ai_generated' => true,
                ], $metadata),
                'sent_at' => now(),
            ]);

            $thread->last_message_at = \Illuminate\Support\Carbon::now();
            if (!$thread->first_outbound_at) {
                $thread->first_outbound_at = \Illuminate\Support\Carbon::now();
            }
            $thread->save();

            return $message;
        } catch (\Throwable $e) {
            Log::error('AiBotService dispatchOutboundMessage Exception: ' . $e->getMessage());
            return null;
        }
    }
}
