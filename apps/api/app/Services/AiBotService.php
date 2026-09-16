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

        $customPrompt = !empty($botAgent->custom_knowledge_prompt) ? "\nMASTER CUSTOM KNOWLEDGE PROMPT:\n" . $botAgent->custom_knowledge_prompt : "";
        $privacyPolicyPrompt = !empty($botAgent->privacy_policy) ? "\nPRIVACY POLICY & DATA SECURITY RULES:\n" . $botAgent->privacy_policy : "\nPRIVACY POLICY & DATA SECURITY RULES:\nHum OTP, Passwords, PINs ya Banking Details kisi ke sath share nahi karte aur na puchte hain.";

        $systemPrompt = <<<PROMPT
You are a warm, helpful sales representative working for {$companyName}.
Your ONLY goal is to have a natural, human, WhatsApp-style conversation with customers based strictly on the Knowledge Base below.

STRICT CONVERSATIONAL RULES:
1. BREVITY (DEFAULT 1 SENTENCE):
   - By default, reply in ONLY 1 short sentence (maximum 15 words).
   - Maximum 2 short sentences ONLY if absolutely necessary.
   - NEVER send large paragraphs or bulleted lists unless the user explicitly asks for detailed info (e.g. "full details do", "brochure send kro").

2. ANSWER LATEST MESSAGE FIRST & NO SCRIPTED FORCING:
   - Always respond directly to what the customer JUST said in their latest message.
   - Do NOT force or restart a scripted sales pitch.
   - If user says "abhi", reply naturally to "abhi" without forcing qualification.

3. NO CALLING PROMISES & UNSUPPORTED CAPABILITIES:
   - Meta WhatsApp integration does NOT support calling or placing outbound phone calls.
   - NEVER claim that you can call, arrange a call, or that a manager/senior will call the customer.
   - NEVER say: "main call karwa deta hoon", "abhi call arrange karta hoon", "5-10 minute me call aa jayega", "manager aapko call karega", "main senior manager se confirm karta hoon", "main message karwata hoon", or "main aapko inform karunga".
   - When a customer asks for a call ("mujhe call kro", "call kar do", "mujhe phone karo", "abhi call kro", "isi number pe call kro", "WhatsApp pe call kar sakte ho?"):
     State clearly in 1 short sentence that WhatsApp calling is not available and invite them to chat here on WhatsApp.

4. NO REPETITION & CONVERSATION MEMORY:
   - NEVER ask for information that the customer has ALREADY provided in the conversation history.
   - NEVER repeat property specs, prices, or contact offers unless asked.

5. ASK ONLY ONE QUESTION AT A TIME:
   - NEVER combine multiple questions into a single message.
   - Ask at most ONE simple question per response, and wait for customer's reply.

6. NATURAL LANGUAGE & HINGLISH MATCHING:
   - Match customer's language (Hinglish/Hindi or English) naturally.
   - Avoid robotic phrases like "poori jankari ke saath aapse baat karein".
   - Use natural phrases like "Ji bilkul", "Theek hai", "Sure", "Haan, bataiye" appropriately.
   - Avoid unnecessary emojis and scripted closings (do NOT say "Thank you! 😊" after every message).

7. COMMON SCENARIO RESPONSES & EMOJIS:
   - Customer: "OTP nahi aa raha" / OTP query -> "Hum OTP ya banking details share nahi karte."
   - Customer: "nahi chahiye" -> "Theek hai sir, koi baat nahi."
   - Customer: "details WhatsApp kar do" -> "Ji, main details WhatsApp par share kar deta hoon."
   - Customer asks for contact details ("contact details", "phone number kya hai", "apka number", "office contact") -> Provide the contact details / phone number listed in Knowledge Base.
   - Customer sends emoji (😂, 👍, 🙂, etc.) -> Reply with matching emoji or warm 1-word reaction (e.g. "😊", "👍"). DO NOT send fallback message.
   - Customer sends short acknowledgment ("ok", "haan", "theek hai", "acha", "hmm") -> Reply naturally (e.g. "Ji", "Theek hai!"). DO NOT send fallback message.

8. FALLBACK DEDUPLICATION & LAST RESORT RULES:
   - Fallback is a LAST RESORT for unlisted questions only.
   - NEVER repeat the exact same fallback response twice in the same conversation.
   - First occurrence of an unlisted question -> "Ji, iski exact jankari mere paas abhi nahi hai."
   - Repeated same/similar unknown question -> "Ji, iski exact jankari abhi available nahi hai."
   - If customer changes topic to a Knowledge Base topic, answer the new topic normally.
   - NEVER invent missing information to avoid fallback.
   - NEVER claim that anyone will call, message, or inform the customer later.

KNOWLEDGE BASE:
{$kbData}
{$customPrompt}

AGENT PERSONA & SYSTEM INSTRUCTIONS:
{$botAgent->system_instructions}
{$privacyPolicyPrompt}
{$dynamicContext}
PROMPT;

        $contents = [];
        foreach ($recentMessages as $msg) {
            $role = $msg->direction === 'inbound' ? 'user' : 'model';
            $contents[] = [
                'role' => $role,
                'parts' => [['text' => (string) $msg->body]],
            ];
        }

        if (empty($contents)) {
            $contents[] = [
                'role' => 'user',
                'parts' => [['text' => $userText]],
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
                // Format messages for OpenAI Chat Completions API
                $openAiMessages = [
                    ['role' => 'system', 'content' => $systemPrompt],
                ];
                foreach ($recentMessages as $msg) {
                    $role = $msg->direction === 'inbound' ? 'user' : 'assistant';
                    $openAiMessages[] = [
                        'role' => $role,
                        'content' => (string) $msg->body,
                    ];
                }

                $openAiModels = array_filter(array_unique([
                    $configuredModel ?: 'gpt-4o-mini',
                    'gpt-4o-mini',
                    'gpt-4o',
                    'gpt-3.5-turbo',
                ]));

                foreach ($openAiModels as $modelName) {
                    try {
                        $response = Http::timeout(10)->withHeaders([
                            'Authorization' => 'Bearer ' . $apiKey,
                            'Content-Type' => 'application/json',
                        ])->post('https://api.openai.com/v1/chat/completions', [
                            'model' => $modelName,
                            'messages' => $openAiMessages,
                            'temperature' => 0.2,
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
                        $response = Http::timeout(10)->withHeaders(['Content-Type' => 'application/json'])
                            ->post($endpoint, [
                                'system_instruction' => [
                                    'parts' => [['text' => $systemPrompt]]
                                ],
                                'contents' => $contents,
                                'generationConfig' => [
                                    'temperature' => 0.2,
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
            Log::warning("AiBotService: AI generation unavailable for tenant {$tenantId}. Checking direct Knowledge Base / Flow match...");
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

            // Keyword-based fallback for common real-estate selections (2BHK, 3BHK, 4BHK)
            if ($aiText === '') {
                if (preg_match('/\b4\s*bhk\b/i', $userText)) {
                    $aiText = "Ji, 4BHK luxury flat ke floor plans aur pricing details hamare sales executive aapse jald share karenge. Kya aap site visit schedule karna chahenge?";
                } elseif (preg_match('/\b3\s*bhk\b/i', $userText)) {
                    $aiText = "Ji, 3BHK flat ke plans aur pricing details hamari team aapse share karegi. Kya aap project location dekhna chahte hain?";
                } elseif (preg_match('/\b2\s*bhk\b/i', $userText)) {
                    $aiText = "Ji, 2BHK flat prime location par available hai. Kya aap brochure chahte hain?";
                }
            }

            if ($aiText === '') {
                $aiText = $fallback;
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
