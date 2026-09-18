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

            // 2. Generate AI Response using OpenAI API
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

        // Simulate natural human typing delay as configured on AI Agent
        $delaySeconds = (int) max(0, $bot->human_delay_seconds ?? 3);
        if ($delaySeconds > 0) {
            sleep(min($delaySeconds, 3600));
        }

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
     * Call OpenAI API with Human Persona System Prompt & Knowledge Base Context
     */
    private static function generateAndSendAiResponse(string $tenantId, MessageThread $thread, AiBotAgent $botAgent, string $userText): void
    {
        // Fetch Tenant AI Setting & Resolve OpenAI API Key
        $aiSetting = TenantAiSetting::query()->where('tenant_id', $tenantId)->first();
        $tenantApiKey = trim((string) ($aiSetting?->api_key ?? ''));

        $openAiKey = !empty($tenantApiKey) ? $tenantApiKey : (env('OPENAI_API_KEY') ?: null);

        if (empty($openAiKey)) {
            Log::warning("AiBotService: OpenAI API key not found for tenant {$tenantId}");
            return;
        }

        // Fetch recent conversation history & inspect for previous fallbacks
        $recentMessages = Message::query()
            ->where('thread_id', $thread->id)
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get()
            ->reverse();

        $fallbackMessage = trim((string) ($botAgent->fallback_message ?? ''));

        // ------------------------------------------------------------------
        // FIX: pehle yeh poori last-10 history me fallback ke first 15 chars
        // dhoondta tha — isse ek purani fallback line permanently future
        // replies ko bhi "fallback already sent" bana deti thi (chahe
        // customer sirf "Hi" hi kyun na bole). Ab sirf SABSE AAKHRI
        // outbound message ko EXACT match check karte hain.
        // ------------------------------------------------------------------
        $fallbackSentInHistory = false;
        $lastOutbound = $recentMessages->filter(fn($m) => $m->direction === 'outbound')->last();
        if ($lastOutbound && $fallbackMessage !== '') {
            $lastOutboundTrimmed = strtolower(trim((string) $lastOutbound->body));
            if ($lastOutboundTrimmed === strtolower(trim($fallbackMessage))) {
                $fallbackSentInHistory = true;
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
                "All factual details (prices, specs, terms, availability) MUST come strictly from the Knowledge Base and Master Prompt below. Do NOT invent outside business facts or unapproved information.";
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

        // Generic platform safety & calling limitation rule
        $promptSections[] = "=== GENERAL PLATFORM SAFETY RULES ===\n" .
            "1. Do NOT claim system actions occurred (e.g. call scheduled, payment processed, brochure sent) unless confirmed by backend context.\n" .
            "2. Outbound voice calling is unavailable on WhatsApp. If customer asks for a call, politely inform them in 1 short sentence that voice calling is unavailable here and you are happy to assist in chat.";

        // Configured Out-of-Scope Fallback rule
        $activeFallbackString = $fallbackMessage ?: "Ji, iski exact jankari mere paas abhi nahi hai.";
        $promptSections[] = "=== OUT-OF-SCOPE FALLBACK RULE ===\n" .
            "If the customer asks for a specific factual detail that genuinely does NOT exist anywhere in your configured Knowledge Base or Master Prompt, output your configured fallback message: \"{$activeFallbackString}\".";

        if ($fallbackSentInHistory) {
            $promptSections[] = "CRITICAL NOTICE: Your fallback message was ALREADY sent as your last reply in this thread. If the customer repeats an unresolvable question, do NOT repeat the fallback sentence verbatim; provide a brief alternative polite response. If the customer's new message is unrelated (a greeting, casual chat, or a new/different question), respond to it normally — do NOT treat it as a continuation of the unresolved question.";
        }

        $systemPrompt = implode("\n\n", $promptSections);

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

        // Generate response using OpenAI API exclusively
        $openAiModelToUse = (!empty($configuredModel) && str_starts_with($configuredModel, 'gpt-')) ? $configuredModel : 'gpt-4o-mini';
        $openAiModels = array_filter(array_unique([
            $openAiModelToUse,
            'gpt-4o-mini',
            'gpt-4o',
            'gpt-3.5-turbo',
        ]));

        $lastFailureReason = null;

        foreach ($openAiModels as $modelName) {
            try {
                // FIX: timeout 12s se 20s kiya — bada system prompt (21 sections + KB)
                // hone se response me zyada time lag sakta hai, khaaskar gpt-4o par.
                $response = Http::timeout(20)->withHeaders([
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
                    $lastFailureReason = "HTTP {$response->status()}: " . substr($response->body(), 0, 300);
                    Log::warning("AiBotService: OpenAI model {$modelName} failed (HTTP {$response->status()}) for tenant {$tenantId}: " . substr($response->body(), 0, 300));
                }
            } catch (\Throwable $e) {
                $lastFailureReason = $e->getMessage();
                Log::error("AiBotService: OpenAI request exception for model {$modelName} (tenant {$tenantId}): " . $e->getMessage(), [
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        // ------------------------------------------------------------------
        // FIX: Pehle yahan OpenAI poori tarah fail hone par ek HARDCODED
        // generic PHP string bhej di jaati thi ("Ji, ye detail abhi
        // available nahi hai.") — jo poora 21-section persona/KB prompt
        // bypass kar deti thi. Isi wajah se "Hi" jaisa greeting bhejne par
        // bhi wahi unrelated fallback line aa rahi thi (screenshots wali
        // dikkat). Ab agar AI generate hi nahi ho paaya, to hum galat
        // generic reply customer ko nahi bhejenge — thread ko flag karke
        // human review ke liye chhod denge.
        // ------------------------------------------------------------------
        if ($aiText === '') {
            Log::error("AiBotService: ALL OpenAI models failed for tenant {$tenantId}, thread {$thread->id}. Reason: " . ($lastFailureReason ?? 'unknown') . ". Skipping AI reply — flagging thread for human review instead of sending a generic mismatched fallback.");

            $thread->update([
                'bot_status' => 'ai_error_needs_review',
            ]);

            return;
        }

        // Simulate natural human typing delay as configured on AI Agent
        $delaySeconds = (int) max(0, $botAgent->human_delay_seconds ?? 3);
        if ($delaySeconds > 0) {
            sleep(min($delaySeconds, 3600));
        }

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
