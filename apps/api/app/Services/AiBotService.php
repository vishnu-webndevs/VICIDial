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
        $fallback = $botAgent->fallback_message ?: "Muje iski exact jankari abhi nahi hai, main apne senior manager se confirm karke aapko call/message karwata hu.";

        // Construct System Prompt enforcing Natural Human Sales Manager Persona
        $tenantObj = Tenant::find($tenantId);
        $companyName = $tenantObj?->name ?: 'our team';

        $systemPrompt = <<<PROMPT
You are a warm, polite Indian Sales Representative / Executive working for {$companyName}.
Your name is friendly and professional. Your goal is to help the customer on WhatsApp in a natural human conversation.

CRITICAL HUMAN PERSONA RULES:
1. NEVER mention that you are an AI, artificial intelligence, bot, or automated language model. Speak 100% naturally as a real human sales manager.
2. Use friendly, natural Hinglish / Hindi / English appropriate for Indian business communication.
3. Keep responses concise (2 to 4 sentences maximum), helpful, and end with a natural follow-up question.
4. STRICT KNOWLEDGE BOUNDARY: You MUST ONLY answer based on the KNOWLEDGE BASE provided below. Do NOT make up prices, offers, or facts outside this context.
5. If the customer asks a question outside your Knowledge Base context, respond with EXACTLY or very closely: "{$fallback}"

KNOWLEDGE BASE CONTEXT:
{$kbData}

{$botAgent->system_instructions}
PROMPT;

        // Fetch last 6 messages from thread for conversation history
        $recentMessages = Message::query()
            ->where('thread_id', $thread->id)
            ->orderBy('created_at', 'desc')
            ->take(6)
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

        // Call Gemini API with model fallback array (handles API model deprecation gracefully)
        $configuredModel = $aiSetting?->default_model;
        $modelsToTry = array_filter(array_unique([
            $configuredModel,
            'gemini-flash-latest',
            'gemini-3.6-flash',
            'gemini-2.5-flash',
            'gemini-1.5-flash',
        ]));

        $aiText = '';
        foreach ($modelsToTry as $modelName) {
            $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$modelName}:generateContent?key={$apiKey}";
            
            $response = Http::timeout(12)->withHeaders(['Content-Type' => 'application/json'])
                ->post($endpoint, [
                    'system_instruction' => [
                        'parts' => [['text' => $systemPrompt]]
                    ],
                    'contents' => $contents,
                    'generationConfig' => [
                        'temperature' => 0.4,
                        'maxOutputTokens' => 300,
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
