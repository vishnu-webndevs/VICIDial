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

            // Find AI Bot Agent attached to thread or thread's campaign
            $botAgent = null;
            if ($thread->ai_bot_agent_id) {
                $botAgent = AiBotAgent::query()
                    ->where('tenant_id', $tenantId)
                    ->where('id', $thread->ai_bot_agent_id)
                    ->where('is_active', true)
                    ->first();
            }

            if (!$botAgent && $thread->project_id) {
                // Try campaign link via project_id / campaign_id if assigned
                $campaign = \Illuminate\Support\Facades\DB::table('campaigns')
                    ->where('tenant_id', $tenantId)
                    ->where('id', $thread->project_id)
                    ->first();
                if ($campaign && !empty($campaign->ai_bot_agent_id)) {
                    $botAgent = AiBotAgent::query()
                        ->where('tenant_id', $tenantId)
                        ->where('id', $campaign->ai_bot_agent_id)
                        ->where('is_active', true)
                        ->first();

                    if ($botAgent) {
                        $thread->update(['ai_bot_agent_id' => $botAgent->id]);
                    }
                }
            }

            if (!$botAgent) {
                // Fallback to tenant's default active bot agent if only 1 active bot exists
                $botAgent = AiBotAgent::query()
                    ->where('tenant_id', $tenantId)
                    ->where('is_active', true)
                    ->first();
            }

            if (!$botAgent) {
                return;
            }

            $userText = trim((string) $inboundMsg->body);
            if ($userText === '') {
                return;
            }

            // 1. Check for Interactive Button Flow Trigger
            $flow = AiBotInteractiveFlow::query()
                ->where('ai_bot_agent_id', $botAgent->id)
                ->whereRaw('LOWER(trigger_keyword) = ?', [strtolower($userText)])
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
        $apiKey = $aiSetting?->api_key ?: env('GEMINI_API_KEY');

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
   - NEVER say: "main call karwa deta hoon", "abhi call arrange karta hoon", "5-10 minute me call aa jayega", "manager aapko call karega", or "callback request forward kar deta hoon".
   - When a customer asks for a call ("mujhe call kro", "call kar do", "mujhe phone karo", "abhi call kro", "isi number pe call kro", "WhatsApp pe call kar sakte ho?"):
     State clearly in 1 short sentence that WhatsApp calling is not available and invite them to chat here on WhatsApp.
     Examples:
     * Customer: "mujhe call kro" / "call kar do" -> Bot: "Ji, WhatsApp par call support available nahi hai. Aap yahan message par pooch sakte hain."
     * Customer: "abhi call kro" / "abhi" -> Bot: "Ji, WhatsApp par call possible nahi hai. Aap yahan message par bataiye, main help kar deta hoon."
     * Customer: "isi number pe" -> Bot: "Ji, yahan call support nahi hai. Aap yahan chat par hi sawaal pooch sakte hain."
     * Customer: "WhatsApp pe call kar sakte ho?" -> Bot: "Nahi, WhatsApp par call support available nahi hai. Aap yahan message par baat kar sakte hain."

4. NO REPETITION & CONVERSATION MEMORY:
   - NEVER ask for information that the customer has ALREADY provided in the conversation history.
   - NEVER repeat property specs, prices, or contact offers unless asked.

5. ASK ONLY ONE QUESTION AT A TIME:
   - NEVER combine multiple questions into a single message.
   - Ask at most ONE simple question per response, and wait for customer's reply.
     Examples:
     * Customer: "2BHK chahiye" -> Bot: "Sure, 2BHK chahiye. Aapka budget kitna hai?"
     * Customer: "45 lakh" -> Bot: "Theek hai, 45 lakh ke around. Aap Totan mein hi dekh rahe hain?"

6. NATURAL LANGUAGE & HINGLISH MATCHING:
   - Match customer's language (Hinglish/Hindi or English) naturally.
   - Avoid robotic phrases like "poori jankari ke saath aapse baat karein".
   - Use natural phrases like "Ji bilkul", "Theek hai", "Sure", "Haan, bataiye" appropriately.
   - Avoid unnecessary emojis and scripted closings (do NOT say "Thank you! 😊" after every message).

7. COMMON SCENARIO RESPONSES:
   - Customer: "OTP nahi aa raha" / OTP query -> "Hum OTP ya banking details share nahi karte."
   - Customer: "nahi chahiye" -> "Theek hai sir, koi baat nahi."
   - Customer: "details WhatsApp kar do" -> "Ji, main details WhatsApp par share kar deta hoon."
   - Customer asks for contact details ("contact details", "phone number kya hai", "apka number", "office contact") -> Provide the contact details / phone number listed in Knowledge Base.

8. STRICT KNOWLEDGE BOUNDARY & SECURITY:
   - Answer ONLY based on Knowledge Base below. For unlisted questions (except OTP safety), say: "{$fallback}".
   - NEVER ask for, handle, or share OTPs, Passwords, PINs, or Bank details.

KNOWLEDGE BASE:
{$kbData}

{$botAgent->system_instructions}
PROMPT;

        // Fetch last 10 messages from thread for comprehensive conversation history
        $recentMessages = Message::query()
            ->where('thread_id', $thread->id)
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get()
            ->reverse();

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

        // Call Gemini API with active model fallback array
        $configuredModel = $aiSetting?->default_model;
        $modelsToTry = array_filter(array_unique([
            $configuredModel ?: 'gemini-flash-latest',
            'gemini-flash-latest',
        ]));

        $aiText = '';
        foreach ($modelsToTry as $modelName) {
            $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$modelName}:generateContent?key={$apiKey}";
            
            $response = Http::timeout(15)->withHeaders(['Content-Type' => 'application/json'])
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
        }

        if ($aiText === '') {
            Log::error("AiBotService: All Gemini models failed or empty response for tenant {$tenantId}. Using agent fallback message.");
            $aiText = $fallback;
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

            $thread->last_message_at = now();
            if (!$thread->first_outbound_at) {
                $thread->first_outbound_at = now();
            }
            $thread->save();

            return $message;
        } catch (\Throwable $e) {
            Log::error('AiBotService dispatchOutboundMessage Exception: ' . $e->getMessage());
            return null;
        }
    }
}
