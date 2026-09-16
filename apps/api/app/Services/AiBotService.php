<?php

namespace App\Services;

use App\Models\AiBotAgent;
use App\Models\AiBotInteractiveFlow;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\Tenant;
use App\Models\TenantAiSetting;
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
                $campaignId = \Illuminate\Support\Facades\DB::table('leads')
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
                \Illuminate\Support\Facades\Log::info("AiBotService: Inbound message from {$thread->counterparty_number} skipped — number is not part of any AI campaign.", [
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
        // Fetch Tenant AI Setting / API Key
        $aiSetting = TenantAiSetting::query()->where('tenant_id', $tenantId)->first();
        $provider = strtolower((string) ($aiSetting?->provider ?? 'gemini'));
        $apiKey = $aiSetting?->api_key;

        if (empty($apiKey)) {
            $apiKey = ($provider === 'openai') ? (env('OPENAI_API_KEY') ?: env('GEMINI_API_KEY')) : (env('GEMINI_API_KEY') ?: env('OPENAI_API_KEY'));
        }

        if (empty($apiKey)) {
            Log::warning("AiBotService: No API key found for tenant {$tenantId}");
            return;
        }

        // Format Knowledge Base Context
        $kbData = is_array($botAgent->knowledge_base) ? json_encode($botAgent->knowledge_base, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : (string) $botAgent->knowledge_base;
        $fallback = $botAgent->fallback_message ?: "Mujhe iski exact jankari abhi nahi hai, main confirm karke aapko bataunga.";

        // Construct System Prompt enforcing Natural Human Sales Manager Persona
        $tenantObj = Tenant::find($tenantId);
        $companyName = $tenantObj?->name ?: 'our team';

        // Inspect conversation history for previous fallback messages
        $recentMessages = Message::query()
            ->where('thread_id', $thread->id)
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get()
            ->reverse();

        $lastModelMessage = null;
        $fallbackSentInHistory = false;

        foreach ($recentMessages as $msg) {
            if ($msg->direction === 'outbound') {
                $lastModelMessage = (string) $msg->body;
                $lowerBody = strtolower($msg->body);
                if (
                    str_contains($lowerBody, 'jankari') ||
                    str_contains($lowerBody, 'available nahi') ||
                    str_contains($lowerBody, 'mere paas abhi nahi') ||
                    ($fallback && str_contains($lowerBody, strtolower(substr($fallback, 0, 15))))
                ) {
                    $fallbackSentInHistory = true;
                }
            }
        }

        // Analyze current user message
        $trimmedUser = trim($userText);
        $cleanUserLower = strtolower($trimmedUser);

        $isEmoji = (preg_match('/^[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}\x{1F900}-\x{1F9FF}\x{1F1E6}-\x{1F1FF}\s]+$/u', $trimmedUser) === 1);
        $acknowledgements = ['ok', 'okay', 'haan', 'han', 'theek hai', 'thik hai', 'acha', 'accha', 'hmm', 'hmmm', 'got it', 'sure', 'right', 'ji'];
        $isAck = in_array($cleanUserLower, $acknowledgements, true);

        $isVoiceOrAudio = ($cleanUserLower === '[voice note]' || $cleanUserLower === '[audio]');
        $isImage = ($cleanUserLower === '[image]' || $cleanUserLower === '[photo]');
        $isMediaPlaceholder = in_array($cleanUserLower, ['[image]', '[photo]', '[video]', '[document]', '[voice note]', '[audio]', '[location]'], true);

        $dynamicContext = "";
        if ($isVoiceOrAudio) {
            $dynamicContext = "\n\nCRITICAL CONTEXT: The customer just sent a voice note/audio message ('{$trimmedUser}'). Acknowledge the voice note warmly in 1 natural sentence (e.g. 'Ji, aapka voice note mil gaya hai! Main ise sun raha hoon, bataiye main aapki kya madad kar sakta hoon?'). DO NOT send any fallback message.";
        } elseif ($isImage) {
            $dynamicContext = "\n\nCRITICAL CONTEXT: The customer just sent a photo ('{$trimmedUser}'). Acknowledge the photo warmly in 1 natural sentence (e.g. 'Ji, photo receive ho gayi hai! Iske baare me bataiye aapko kya details chahiye?'). DO NOT send any fallback message.";
        } elseif ($isEmoji) {
            $dynamicContext = "\n\nCRITICAL CONTEXT: The customer sent an emoji ('{$trimmedUser}'). Respond ONLY with a friendly emoji or 1-word reaction (e.g. '😊' or 'Ji!'). DO NOT send any fallback message or sales pitch.";
        } elseif ($isAck) {
            $dynamicContext = "\n\nCRITICAL CONTEXT: The customer sent a short acknowledgment ('{$trimmedUser}'). Reply warmly in 1 short phrase (e.g. 'Ji, bataiye' or 'Theek hai!'). DO NOT send any fallback message or sales pitch.";
        } elseif ($fallbackSentInHistory) {
            $dynamicContext = "\n\nCRITICAL CONTEXT: A fallback message was ALREADY sent in this conversation for an out-of-scope question.\n" .
                "- If customer asks a NEW question in Knowledge Base, answer it directly using Knowledge Base.\n" .
                "- If customer REPEATS the same unknown question, DO NOT repeat the previous fallback response! Reply ONLY: 'Ji, iski exact jankari abhi available nahi hai.'\n" .
                "- NEVER repeat the previous fallback response verbatim.\n" .
                "- NEVER claim anyone will call, message, or inform them later.";
        }

        $agentName = trim((string) ($botAgent->name ?? 'AI Assistant'));
        $customKnowledgeText = trim((string) ($botAgent->custom_knowledge_prompt ?? ''));
        $instructionsText = trim((string) ($botAgent->system_instructions ?? ''));
        $privacyPolicyText = trim((string) ($botAgent->privacy_policy ?? ''));
        $fallbackMessage = trim((string) ($botAgent->fallback_message ?: 'Mujhe iski exact jankari abhi nahi hai, main confirm karke aapko bataunga.'));

        $kbData = is_array($botAgent->knowledge_base) ? json_encode($botAgent->knowledge_base, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : (string) $botAgent->knowledge_base;

        $tenantObj = Tenant::find($tenantId);
        $companyName = $tenantObj?->name ?: 'our company';

        // Construct 100% DYNAMIC System Prompt based purely on fields configured in Create/Edit AI Bot Agent form
        $promptSections = [];
        $promptSections[] = "You are an AI Assistant named '{$agentName}' representing {$companyName}.";

        if (!empty($instructionsText)) {
            $promptSections[] = "=== AGENT PERSONA & INSTRUCTIONS (FROM BOT AGENT FORM) ===\n" . $instructionsText;
        }

        if (!empty($customKnowledgeText)) {
            $promptSections[] = "=== MASTER KNOWLEDGE & DETAILED PROMPT (FROM BOT AGENT FORM) ===\n" . $customKnowledgeText;
        }

        if (!empty($kbData) && $kbData !== '[]' && $kbData !== 'null') {
            $promptSections[] = "=== KNOWLEDGE BASE (FAQS FROM BOT AGENT FORM) ===\n" . $kbData;
        }

        if (!empty($privacyPolicyText)) {
            $promptSections[] = "=== PRIVACY & DATA SECURITY RULES ===\n" . $privacyPolicyText;
        }

        $promptSections[] = <<<RULES
=== CONVERSATIONAL & ACCURACY RULES ===
1. GREETINGS, ACKNOWLEDGMENTS & SMALL TALK (CRITICAL — NEVER USE FALLBACK HERE):
   - When customer sends greetings ("hi", "hello", "hey", "namaste", "good morning", etc.):
     DO NOT send any fallback message! Reply warmly: "Hello sir! Main {$companyName} se aapki kya madad kar sakta hoon?"
   - When customer sends short acknowledgments ("Ji", "ok", "haan", "theek hai", "hmm", etc.):
     DO NOT send any fallback message! Reply politely: "Ji sir, bataiye aapko kis baare me jankari chahiye?"
   - When customer asks about your capabilities ("phir kya pata hai", "aap kya bata sakte ho", "kya information hai"):
     DO NOT send any fallback message! Summarize your main services, projects, or offerings based on the Master Knowledge prompt above.

2. PRIMARY KNOWLEDGE COMPLIANCE:
   - Carefully study and learn from all Agent Instructions, Master Knowledge Prompts, and FAQs provided above.
   - When the customer asks about any topic, product, service, price, offer, or specification detailed in the prompt above, YOU MUST EXTRACT AND PROVIDE THE ACTUAL ACCURATE DETAILS FROM THE PROMPT ABOVE.
   - NEVER state that you don't have information if the answer or context is present in or can be inferred from the prompts provided.

3. CONVERSATIONAL STYLE & BREVITY:
   - Reply naturally, warmly, and concisely for WhatsApp messaging (typically 1 to 3 sentences).
   - Respond directly to what the customer just asked in their latest message.
   - Match the customer's language style naturally (Hinglish/Hindi or English).

4. PREVENT REPETITION:
   - NEVER repeat the exact same sentence or question that was already sent in earlier messages in this conversation.

5. UNSUPPORTED PHONE CALLING:
   - Outbound voice calling is not supported via WhatsApp. If customer asks for a phone call ("call karo"), politely inform them in 1 short sentence that voice calling is unavailable on this WhatsApp number and you are ready to help them right here.

6. FALLBACK STATEMENT (FOR UNRELATED SPECIFIC QUESTIONS ONLY):
   - ONLY if the customer asks a specific question that is completely unrelated to {$companyName} and totally absent from ALL prompts above, reply using the fallback response: "{$fallbackMessage}".
   - NEVER use the fallback message for greetings, "hi", "Ji", or general conversational queries!
{$dynamicContext}
RULES;

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

        // Call OpenAI or Gemini API based on provider and API Key
        $provider = strtolower((string) ($aiSetting?->provider ?? 'gemini'));
        if (!empty($apiKey) && str_starts_with(trim($apiKey), 'sk-')) {
            $provider = 'openai';
        }

        $configuredModel = $aiSetting?->default_model;
        $aiText = '';

        if (!empty($apiKey)) {
            if ($provider === 'openai') {
                $openAiModels = array_filter(array_unique([
                    $configuredModel ?: 'gpt-4o-mini',
                    'gpt-4o-mini',
                    'gpt-4o',
                    'gpt-3.5-turbo',
                ]));

                foreach ($openAiModels as $modelName) {
                    try {
                        $response = Http::timeout(12)->withHeaders([
                            'Authorization' => 'Bearer ' . $apiKey,
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
                                break;
                            }
                        } else {
                            Log::warning("AiBotService: OpenAI model {$modelName} failed ({$response->status()}), trying next model...", [
                                'body' => $response->body(),
                            ]);
                        }
                    } catch (\Throwable $e) {
                        Log::warning("AiBotService: OpenAI request exception for model {$modelName}: " . $e->getMessage());
                    }
                }
            } else {
                // Call Gemini API with active model fallback array
                $modelsToTry = array_filter(array_unique([
                    ($configuredModel && $configuredModel !== 'gemini-flash-latest') ? $configuredModel : 'gemini-1.5-flash',
                    'gemini-1.5-flash',
                    'gemini-2.0-flash',
                    'gemini-1.5-flash-latest',
                    'gemini-1.5-pro',
                ]));

                foreach ($modelsToTry as $modelName) {
                    $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$modelName}:generateContent?key={$apiKey}";
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
                                break;
                            }
                        } else {
                            Log::warning("AiBotService: Gemini model {$modelName} failed ({$response->status()}), trying next model...", [
                                'body' => $response->body(),
                            ]);
                        }
                    } catch (\Throwable $e) {
                        Log::warning("AiBotService: Gemini request exception for model {$modelName}: " . $e->getMessage());
                    }
                }
            }
        }

        if ($aiText === '') {
            Log::warning("AiBotService: AI generation unavailable for tenant {$tenantId}. Checking direct Knowledge Base Q&A...");
            $kbArray = is_array($botAgent->knowledge_base) ? $botAgent->knowledge_base : json_decode((string)$botAgent->knowledge_base, true);
            $userLower = strtolower($userText);

            if (is_array($kbArray)) {
                foreach ($kbArray as $qa) {
                    $qLower = strtolower($qa['question'] ?? '');
                    if ($qLower !== '' && (str_contains($userLower, $qLower) || str_contains($qLower, $userLower))) {
                        $aiText = (string) ($qa['answer'] ?? '');
                        break;
                    }
                }
            }

            if ($aiText === '') {
                $aiText = $fallbackMessage;
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
