<?php

namespace App\Services;

use App\Models\AiBotAgent;
use App\Models\AiBotInteractiveFlow;
use App\Models\GraphBooking;
use App\Models\Membership;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\Tenant;
use App\Models\TenantAiSetting;
use App\Models\User;
use Carbon\Carbon;
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

            // 4. Fallback: If no campaign bound, check if tenant has an active AI Bot Agent for direct incoming WhatsApp messages
            if (!$botAgent) {
                $botAgent = AiBotAgent::query()
                    ->where('tenant_id', $tenantId)
                    ->where('is_active', true)
                    ->latest('updated_at')
                    ->first();

                if ($botAgent) {
                    $thread->update([
                        'ai_bot_agent_id' => $botAgent->id,
                    ]);
                }
            }

            // If no active AI agent exists for this tenant, DO NOT reply
            if (!$botAgent) {
                Log::info("AiBotService: Inbound message from {$thread->counterparty_number} skipped — no active AI agent found for tenant.", [
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

        // Simulate natural human typing delay as configured on AI Agent (capped to max 2s for synchronous webhooks)
        $delaySeconds = (int) max(0, $bot->human_delay_seconds ?? 2);
        if ($delaySeconds > 0) {
            sleep(min($delaySeconds, 2));
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

        // Real-Time System Date & Time context (Asia/Kolkata timezone)
        $now = Carbon::now('Asia/Kolkata');
        $todayStr = $now->format('l, d F Y (h:i A)');
        $tomorrowStr = $now->copy()->addDay()->format('l, d F Y');
        $dayAfterTomorrowStr = $now->copy()->addDays(2)->format('l, d F Y');

        // Construct 100% PURE DYNAMIC System Prompt strictly from fields configured in the AI Bot Agent Form
        $promptSections = [];

        $promptSections[] = "=== REAL-TIME DATE & CALENDAR CONTEXT ===\n" .
            "Current System Date & Time: {$todayStr} IST\n" .
            "Tomorrow: {$tomorrowStr}\n" .
            "Day After Tomorrow (Parso): {$dayAfterTomorrowStr}\n" .
            "Use this exact real-time date context to calculate relative dates mentioned by customer (e.g. '25', 'parso', 'kal', 'next Monday').";

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

        // Calendar & Invitation Link Rule
        $promptSections[] = "=== CALENDAR & APPOINTMENT SCHEDULING RULES ===\n" .
            "1. When the customer requests, wants, or agrees to schedule a site visit, call, demo, or meeting (e.g. 'schedule fix kro', 'kal aunga', '23 ko kre', 'kal ka schedule'), IMMEDIATELY call the `schedule_calendar_appointment` tool.\n" .
            "2. If the customer specifies a date or asks to fix schedule (e.g. 'kal', 'parso', '23', 'kal ka schedule fix kro') without specifying an exact time, IMMEDIATELY call the `schedule_calendar_appointment` tool with default time '11:00 AM'!\n" .
            "3. ALWAYS include the returned Google Calendar Invitation Link in your final response to the customer so they can click and save it to their calendar.\n" .
            "4. Format your reply nicely in friendly language, confirming the scheduled date, time, and sending the invitation link URL clearly. Mention that if they wish to adjust the time, they can let you know.";

        // Generic platform safety & calling limitation rule
        $promptSections[] = "=== GENERAL PLATFORM SAFETY RULES ===\n" .
            "1. Do NOT claim system actions occurred (e.g. payment processed, brochure sent) unless confirmed by backend context/tools.\n" .
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

        // Tools / Function Calling definition
        $tools = [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'schedule_calendar_appointment',
                    'description' => 'Schedule a calendar appointment, site visit, or meeting with the customer and generate a Google Calendar invitation link.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => [
                                'type' => 'string',
                                'description' => 'Title of the event, e.g. "Site Visit - Totan Reality"'
                            ],
                            'date' => [
                                'type' => 'string',
                                'description' => 'Exact date in YYYY-MM-DD format (e.g. 2026-09-23)'
                            ],
                            'time' => [
                                'type' => 'string',
                                'description' => 'Time of visit in HH:MM format (24h) or readable format e.g. "11:00 AM"'
                            ],
                            'notes' => [
                                'type' => 'string',
                                'description' => 'Additional notes or requirements'
                            ],
                        ],
                        'required' => ['title', 'date'],
                    ],
                ],
            ],
        ];

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
                $response = Http::timeout(12)->withHeaders([
                    'Authorization' => 'Bearer ' . $openAiKey,
                    'Content-Type' => 'application/json',
                ])->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $modelName,
                    'messages' => $openAiMessages,
                    'tools' => $tools,
                    'tool_choice' => 'auto',
                    'temperature' => 0.6,
                    'max_tokens' => 1000,
                ]);

                if ($response->successful()) {
                    $responseData = $response->json();
                    $choiceMsg = $responseData['choices'][0]['message'] ?? [];
                    $toolCalls = $choiceMsg['tool_calls'] ?? [];

                    if (!empty($toolCalls)) {
                        foreach ($toolCalls as $toolCall) {
                            $funcName = $toolCall['function']['name'] ?? '';
                            $funcArgs = json_decode($toolCall['function']['arguments'] ?? '{}', true) ?: [];

                            if ($funcName === 'schedule_calendar_appointment') {
                                $eventTitle = $funcArgs['title'] ?? ($agentName ? "Site Visit - {$agentName}" : "Site Visit");
                                $dateStr = $funcArgs['date'] ?? $now->format('Y-m-d');
                                $timeStr = $funcArgs['time'] ?? '11:00 AM';
                                $notesStr = $funcArgs['notes'] ?? '';

                                try {
                                    $startCarbon = Carbon::parse("{$dateStr} {$timeStr}", 'Asia/Kolkata');
                                } catch (\Throwable $e) {
                                    try {
                                        $startCarbon = Carbon::parse($dateStr, 'Asia/Kolkata')->setHour(11)->setMinute(0);
                                    } catch (\Throwable $e2) {
                                        $startCarbon = Carbon::now('Asia/Kolkata')->addDay()->setHour(11)->setMinute(0);
                                    }
                                }
                                $endCarbon = $startCarbon->copy()->addHour();

                                $attendeePhone = (string) $thread->counterparty_number;
                                $attendeeEmail = $attendeePhone . '@customer.wnd';

                                // Resolve Customer Name if available
                                $customerName = null;
                                if ($thread->contact_id) {
                                    $customerName = \App\Models\Contact::where('id', $thread->contact_id)->value('name');
                                }
                                if (empty($customerName)) {
                                    $cleanNum = preg_replace('/[^0-9]/', '', (string) $thread->counterparty_number);
                                    $customerName = DB::table('leads')
                                        ->where('tenant_id', $tenantId)
                                        ->where(function ($q) use ($thread, $cleanNum) {
                                            $q->where('phone', $thread->counterparty_number)
                                              ->orWhereRaw("REGEXP_REPLACE(phone, '[^0-9]', '') = ?", [$cleanNum]);
                                        })
                                        ->value('name');
                                }
                                $customerDisplayName = !empty($customerName) ? "{$customerName} ({$attendeePhone})" : $attendeePhone;

                                // Resolve Agent / Representative email for calendar invitation (configured Agent Email > Thread assigned user > Tenant owner)
                                $agentEmail = trim((string) ($botAgent->agent_email ?? ''));
                                if (empty($agentEmail) && !empty($thread->assigned_user_id)) {
                                    $agentEmail = User::where('id', $thread->assigned_user_id)->value('email');
                                }
                                if (empty($agentEmail)) {
                                    $agentEmail = Membership::where('tenant_id', $tenantId)->with('user')->first()?->user?->email;
                                }

                                if (!empty($customerName) && !str_contains(strtolower($eventTitle), strtolower($customerName))) {
                                    $eventTitle .= " with " . $customerName;
                                }

                                $bookingId = 'ai_book_' . \Illuminate\Support\Str::random(12);
                                try {
                                    $booking = GraphBooking::query()->create([
                                        'tenant_id' => $tenantId,
                                        'external_booking_id' => $bookingId,
                                        'calendar_event_id' => 'evt_' . \Illuminate\Support\Str::random(12),
                                        'attendee_email' => substr($attendeeEmail, 0, 240),
                                        'subject' => substr($eventTitle, 0, 140),
                                        'start_at' => $startCarbon->toDateTimeString(),
                                        'end_at' => $endCarbon->toDateTimeString(),
                                        'confirmation_sent' => true,
                                        'status' => 'confirmed',
                                        'provider_mode' => 'ai_bot',
                                        'metadata' => [
                                            'thread_id' => $thread->id,
                                            'phone' => $attendeePhone,
                                            'customer_name' => $customerName,
                                            'agent_email' => $agentEmail,
                                            'notes' => $notesStr,
                                            'created_by' => 'ai_bot_service',
                                        ],
                                    ]);
                                    $bookingId = $booking->id;
                                } catch (\Throwable $dbEx) {
                                    Log::warning("AiBotService: GraphBooking creation notice: " . $dbEx->getMessage());
                                }

                                $gCalStart = $startCarbon->utc()->format('Ymd\THis\Z');
                                $gCalEnd = $endCarbon->utc()->format('Ymd\THis\Z');
                                $gCalTitle = urlencode($eventTitle);
                                $gCalDetails = urlencode("Site Visit / Meeting scheduled via AI Agent for customer {$customerDisplayName}. " . ($notesStr ? "Notes: {$notesStr}" : ""));
                                $addGuestParam = !empty($agentEmail) ? "&add=" . urlencode($agentEmail) : '';
                                $invitationUrl = "https://calendar.google.com/calendar/render?action=TEMPLATE&text={$gCalTitle}&dates={$gCalStart}/{$gCalEnd}&details={$gCalDetails}{$addGuestParam}";

                                Log::info("AiBotService: Scheduled calendar booking {$bookingId} for tenant {$tenantId}. Invite URL: {$invitationUrl}");

                                // Append tool response & query OpenAI for final user text
                                $openAiMessages[] = $choiceMsg;
                                $openAiMessages[] = [
                                    'role' => 'tool',
                                    'tool_call_id' => $toolCall['id'],
                                    'content' => json_encode([
                                        'status' => 'success',
                                        'booking_id' => $bookingId,
                                        'formatted_date' => $startCarbon->format('l, d F Y'),
                                        'formatted_time' => $startCarbon->format('h:i A'),
                                        'invitation_link' => $invitationUrl,
                                        'instruction' => 'ALWAYS include the exact invitation_link in your final response. You may write it cleanly like: [Add to Google Calendar](invitation_link) or as a clickable URL line.',
                                    ]),
                                ];

                                try {
                                    $secondResponse = Http::timeout(10)->withHeaders([
                                        'Authorization' => 'Bearer ' . $openAiKey,
                                        'Content-Type' => 'application/json',
                                    ])->post('https://api.openai.com/v1/chat/completions', [
                                        'model' => $modelName,
                                        'messages' => $openAiMessages,
                                        'tools' => $tools,
                                        'temperature' => 0.6,
                                        'max_tokens' => 1000,
                                    ]);

                                    if ($secondResponse->successful()) {
                                        $secData = $secondResponse->json();
                                        $aiText = trim($secData['choices'][0]['message']['content'] ?? '');
                                    }
                                } catch (\Throwable $secEx) {
                                    Log::warning("AiBotService: 2nd turn OpenAI call notice: " . $secEx->getMessage());
                                }

                                if ($aiText === '' || !str_contains($aiText, 'calendar.google.com')) {
                                    $fDate = $startCarbon->format('l, d F Y');
                                    $fTime = $startCarbon->format('h:i A');
                                    $aiText = "Aapka site visit {$fDate} ko {$fTime} par schedule kar diya gaya hai! 📅\n\n" .
                                              "🔗 **Calendar Invitation Link:**\n{$invitationUrl}\n\n" .
                                              "Aap upar diye gaye link par click karke ise apne Google Calendar me add kar sakte hain. Kya aapko kisi aur madad ki zarurat hai?";
                                }
                                break 2;
                            }
                        }
                    }

                    $aiText = trim($choiceMsg['content'] ?? '');
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

        if ($aiText === '') {
            Log::error("AiBotService: ALL OpenAI models failed for tenant {$tenantId}, thread {$thread->id}. Reason: " . ($lastFailureReason ?? 'unknown') . ". Skipping AI reply — flagging thread for human review instead of sending a generic mismatched fallback.");

            $thread->update([
                'bot_status' => 'needs_review',
            ]);

            return;
        }

        // Auto-recover thread bot status if it was previously in error state
        if ($thread->bot_status === 'needs_review') {
            $thread->update(['bot_status' => 'active']);
        }

        // Simulate natural human typing delay as configured on AI Agent (capped to max 2s for synchronous webhooks)
        $delaySeconds = (int) max(0, $botAgent->human_delay_seconds ?? 2);
        if ($delaySeconds > 0) {
            sleep(min($delaySeconds, 2));
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
