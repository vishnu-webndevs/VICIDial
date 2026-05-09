<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadTimelineItem;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\MessageThread;
use App\Models\MessagingOptOut;
use App\Models\MessageTemplate;
use App\Models\LeadList;
use App\Models\ProviderAccount;
use App\Models\TenantSetting;
use App\Services\Messaging\MessageTemplateRenderer;
use App\Services\Messaging\MediaAttachmentService;
use App\Services\Messaging\SmsService;
use App\Services\Messaging\WhatsAppService;
use App\Jobs\DispatchOutboundMessageJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MessagingController extends Controller
{
    private const SMS_OPT_OUT_KEYWORDS = [
        'STOP',
        'STOPALL',
        'UNSUBSCRIBE',
        'CANCEL',
        'END',
        'QUIT',
    ];

    private const SMS_OPT_IN_KEYWORDS = [
        'START',
        'YES',
        'UNSTOP',
    ];

    public function __construct(
        private readonly SmsService $smsService,
        private readonly WhatsAppService $whatsAppService,
        private readonly MessageTemplateRenderer $templateRenderer,
        private readonly MediaAttachmentService $mediaAttachmentService,
    ) {
    }

    public function sendSms(Request $request, string $leadId): JsonResponse
    {
        return $this->sendOutbound($request, $leadId, 'sms');
    }

    public function sendWhatsapp(Request $request, string $leadId): JsonResponse
    {
        return $this->sendOutbound($request, $leadId, 'whatsapp');
    }

    public function sendBulkSms(Request $request): JsonResponse
    {
        return $this->sendBulkOutbound($request, 'sms');
    }

    public function sendBulkWhatsapp(Request $request): JsonResponse
    {
        return $this->sendBulkOutbound($request, 'whatsapp');
    }

    public function webhookSms(Request $request): JsonResponse
    {
        return $this->handleWebhookInbound($request, 'sms');
    }

    public function webhookWhatsapp(Request $request): JsonResponse
    {
        return $this->handleWebhookInbound($request, 'whatsapp');
    }

    public function webhookMessageStatus(Request $request): JsonResponse
    {
        $payload = $request->all();
        $accountSid = (string) ($payload['AccountSid'] ?? '');
        $provider = $this->resolveTwilioProvider($accountSid);
        if (! $provider) {
            return response()->json(['received' => true], 202);
        }

        $messageSid = (string) ($payload['MessageSid'] ?? '');
        $messageStatus = (string) ($payload['MessageStatus'] ?? ($payload['SmsStatus'] ?? ''));
        if ($messageSid === '' || $messageStatus === '') {
            return response()->json(['received' => true], 202);
        }

        $message = Message::query()
            ->where('tenant_id', $provider->tenant_id)
            ->where('provider_message_id', $messageSid)
            ->first();

        if ($message) {
            $message->status = $messageStatus;
            if (in_array($messageStatus, ['delivered', 'read'], true)) {
                $message->delivered_at = now();
            }
            $message->metadata = array_merge((array) ($message->metadata ?? []), [
                'status_callback' => $payload,
            ]);
            $message->save();
        }

        return response()->json(['received' => true], 200);
    }

    public function webhookMetaWhatsapp(Request $request)
    {
        if ($request->isMethod('GET')) {
            $mode = (string) $request->query('hub_mode', $request->query('hub.mode', ''));
            $token = (string) $request->query('hub_verify_token', $request->query('hub.verify_token', ''));
            $challenge = (string) $request->query('hub_challenge', $request->query('hub.challenge', ''));

            if ($mode === 'subscribe' && $token !== '' && $challenge !== '') {
                $provider = $this->resolveMetaWhatsappProviderByVerifyToken($token);
                if ($provider) {
                    return response($challenge, 200);
                }
            }

            return response('forbidden', 403);
        }

        $payload = (array) $request->all();
        $phoneNumberId = (string) data_get($payload, 'entry.0.changes.0.value.metadata.phone_number_id', '');
        $provider = $phoneNumberId !== '' ? $this->resolveMetaWhatsappProviderByPhoneNumberId($phoneNumberId) : null;
        if (! $provider) {
            return response()->json(['received' => true], 202);
        }

        $tenantId = (string) $provider->tenant_id;

        $statuses = (array) data_get($payload, 'entry.0.changes.0.value.statuses', []);
        foreach ($statuses as $row) {
            $messageId = (string) data_get($row, 'id', '');
            $status = strtolower((string) data_get($row, 'status', ''));
            if ($messageId === '' || $status === '') {
                continue;
            }

            $message = Message::query()
                ->where('tenant_id', $tenantId)
                ->where('provider_message_id', $messageId)
                ->first();
            if (! $message) {
                continue;
            }

            $message->status = $status;
            if (in_array($status, ['delivered', 'read'], true)) {
                $message->delivered_at = $message->delivered_at ?: now();
            }
            $message->metadata = array_merge((array) ($message->metadata ?? []), [
                'status_callback' => $payload,
                'provider_account_id' => $provider->id,
            ]);
            $message->save();
        }

        $messages = (array) data_get($payload, 'entry.0.changes.0.value.messages', []);
        foreach ($messages as $row) {
            $from = (string) data_get($row, 'from', '');
            $body = (string) data_get($row, 'text.body', '');
            $providerMessageId = (string) data_get($row, 'id', '');
            if ($from === '' || $providerMessageId === '' || $body === '') {
                continue;
            }

            $normalizedFrom = Str::startsWith($from, '+') ? $from : '+'.$from;
            $thread = MessageThread::query()->firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'channel' => 'whatsapp',
                    'counterparty_number' => $normalizedFrom,
                ],
                [
                    'contact_id' => null,
                    'project_id' => null,
                    'assigned_user_id' => null,
                    'status' => 'open',
                    'priority' => 'normal',
                ]
            );
            $thread->last_message_at = now();
            if (! $thread->first_inbound_at) {
                $thread->first_inbound_at = now();
            }
            $thread->save();

            $message = Message::query()->create([
                'tenant_id' => $tenantId,
                'thread_id' => $thread->id,
                'direction' => 'inbound',
                'status' => 'received',
                'body' => $body,
                'sent_by_user_id' => null,
                'provider_message_id' => $providerMessageId,
                'metadata' => [
                    'channel' => 'whatsapp',
                    'provider_account_id' => $provider->id,
                    'meta' => $payload,
                ],
                'sent_at' => now(),
            ]);

            $lead = Lead::query()
                ->where('tenant_id', $tenantId)
                ->where('phone', $normalizedFrom)
                ->first();
            if ($lead) {
                $this->appendTimelineMessage(
                    tenantId: $tenantId,
                    lead: $lead,
                    message: $message,
                    channel: 'whatsapp',
                    direction: 'inbound',
                    actorId: null
                );
            }
        }

        return response()->json(['received' => true], 200);
    }

    private function sendOutbound(Request $request, string $leadId, string $channel): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'content' => ['required_without:template_key', 'string', 'max:5000'],
            'template_key' => ['required_without:content', 'string', 'max:80'],
            'variables' => ['nullable', 'array', 'max:50'],
            'provider_account_id' => ['nullable', 'uuid'],
            'campaign_id' => ['nullable', 'uuid'],
            'campaign_run_id' => ['nullable', 'uuid'],
        ]);

        $lead = Lead::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $leadId)
            ->firstOrFail();

        if ($channel === 'sms' && $this->isOptedOut($tenant->id, (string) $lead->phone, 'sms')) {
            return response()->json([
                'error' => [
                    'code' => 'SMS_OPTED_OUT',
                    'message' => 'Recipient has opted-out from SMS messaging.',
                ],
            ], 422);
        }

        $content = (string) ($validated['content'] ?? '');
        $templateKey = (string) ($validated['template_key'] ?? '');
        if ($templateKey !== '') {
            $template = MessageTemplate::query()
                ->where('tenant_id', $tenant->id)
                ->where('channel', $channel)
                ->where('key', $templateKey)
                ->where('is_active', true)
                ->first();

            if (! $template) {
                return response()->json([
                    'error' => [
                        'code' => 'TEMPLATE_NOT_FOUND',
                        'message' => 'Message template not found.',
                    ],
                ], 404);
            }

            $variables = array_merge([
                'lead' => [
                    'id' => $lead->id,
                    'full_name' => $lead->full_name,
                    'phone' => $lead->phone,
                    'email' => $lead->email,
                    'company' => $lead->company,
                ],
            ], (array) ($validated['variables'] ?? []));

            $content = $this->templateRenderer->render((string) $template->body, $variables);
            $content = trim($content);
            if ($content === '') {
                return response()->json([
                    'error' => [
                        'code' => 'TEMPLATE_RENDER_EMPTY',
                        'message' => 'Rendered template content is empty.',
                    ],
                ], 422);
            }
        }

        $provider = null;
        if (! empty($validated['provider_account_id'])) {
            $provider = ProviderAccount::query()
                ->where('tenant_id', $tenant->id)
                ->where('id', $validated['provider_account_id'])
                ->where('status', 'active')
                ->first();
        } else {
            $provider = ProviderAccount::query()
                ->where('tenant_id', $tenant->id)
                ->where('status', 'active')
                ->latest('created_at')
                ->first();
        }
        $providerCredentials = $provider ? (array) $provider->credentials_encrypted : null;

        $statusCallbackUrl = rtrim((string) config('app.url'), '/').'/api/v1/webhooks/twilio/message-status';
        $result = $channel === 'sms'
            ? $this->smsService->send((string) $lead->phone, $content, $statusCallbackUrl, $providerCredentials)
            : $this->whatsAppService->send((string) $lead->phone, $content, $statusCallbackUrl, $providerCredentials);

        if (($result['ok'] ?? false) !== true) {
            return response()->json([
                'error' => [
                    'code' => 'MESSAGE_SEND_FAILED',
                    'message' => (string) ($result['error'] ?? 'Message delivery failed.'),
                ],
            ], 422);
        }

        $thread = MessageThread::query()->firstOrCreate(
            [
                'tenant_id' => $tenant->id,
                'channel' => $channel,
                'counterparty_number' => $lead->phone,
            ],
            [
                'contact_id' => null,
                'project_id' => null,
                'assigned_user_id' => $request->user()?->id,
                'status' => 'open',
                'priority' => 'normal',
            ]
        );
        $thread->last_message_at = now();
        if (! $thread->first_outbound_at) {
            $thread->first_outbound_at = now();
        }
        $thread->save();

        $message = Message::query()->create([
            'tenant_id' => $tenant->id,
            'thread_id' => $thread->id,
            'direction' => 'outbound',
            'status' => (string) ($result['status'] ?? 'queued'),
            'body' => $content,
            'sent_by_user_id' => $request->user()?->id,
            'provider_message_id' => (string) ($result['provider_message_id'] ?? ''),
            'metadata' => [
                'channel' => $channel,
                'template_key' => $templateKey !== '' ? $templateKey : null,
                'provider_account_id' => $provider?->id,
                'campaign_id' => $validated['campaign_id'] ?? null,
                'campaign_run_id' => $validated['campaign_run_id'] ?? null,
            ],
            'sent_at' => now(),
        ]);

        $this->appendTimelineMessage(
            tenantId: $tenant->id,
            lead: $lead,
            message: $message,
            channel: $channel,
            direction: 'outbound',
            actorId: $request->user()?->id
        );

        return response()->json(['data' => $message], 201);
    }

    private function sendBulkOutbound(Request $request, string $channel): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'lead_ids' => ['required_without:lead_list_id', 'array', 'min:1', 'max:500'],
            'lead_ids.*' => ['required_with:lead_ids', 'uuid'],
            'lead_list_id' => ['required_without:lead_ids', 'uuid'],
            'content' => ['required_without:template_key', 'string', 'max:5000'],
            'template_key' => ['required_without:content', 'string', 'max:80'],
            'variables' => ['nullable', 'array', 'max:50'],
            'provider_account_id' => ['nullable', 'uuid'],
            'campaign_id' => ['nullable', 'uuid'],
            'campaign_run_id' => ['nullable', 'uuid'],
        ]);

        $batchId = (string) Str::uuid();
        $leadIds = [];
        if (! empty($validated['lead_list_id'])) {
            $list = LeadList::query()
                ->where('tenant_id', $tenant->id)
                ->where('id', $validated['lead_list_id'])
                ->firstOrFail();
            $leadIds = $list->leads()
                ->where('leads.tenant_id', $tenant->id)
                ->pluck('leads.id')
                ->map(fn ($id) => (string) $id)
                ->values()
                ->all();
        } else {
            $leadIds = array_values(array_unique(array_map('strval', (array) ($validated['lead_ids'] ?? []))));
        }

        foreach ($leadIds as $leadId) {
            DispatchOutboundMessageJob::dispatch(
                tenantId: $tenant->id,
                leadId: $leadId,
                channel: $channel,
                content: (string) ($validated['content'] ?? ''),
                templateKey: (string) ($validated['template_key'] ?? ''),
                variables: (array) ($validated['variables'] ?? []),
                sentByUserId: $request->user()?->id,
                bulkBatchId: $batchId,
                providerAccountId: ! empty($validated['provider_account_id']) ? (string) $validated['provider_account_id'] : null,
                campaignId: ! empty($validated['campaign_id']) ? (string) $validated['campaign_id'] : null,
                campaignRunId: ! empty($validated['campaign_run_id']) ? (string) $validated['campaign_run_id'] : null,
            );
        }

        return response()->json([
            'data' => [
                'bulk_batch_id' => $batchId,
                'queued' => count($leadIds),
                'channel' => $channel,
            ],
        ], 202);
    }

    private function handleWebhookInbound(Request $request, string $channel): JsonResponse
    {
        $payload = $request->all();
        $accountSid = (string) ($payload['AccountSid'] ?? '');
        $from = $this->normalizePhone((string) ($payload['From'] ?? ''));
        $to = $this->normalizePhone((string) ($payload['To'] ?? ''));
        $body = (string) ($payload['Body'] ?? '');

        $provider = $this->resolveTwilioProvider($accountSid);
        if (! $provider) {
            return response()->json(['received' => true], 202);
        }

        if ($channel === 'sms' && $from !== '') {
            $normalizedBody = strtoupper(trim($body));
            if ($this->matchesKeyword($normalizedBody, self::SMS_OPT_OUT_KEYWORDS)) {
                MessagingOptOut::query()->updateOrCreate([
                    'tenant_id' => $provider->tenant_id,
                    'phone' => $from,
                    'channel' => 'sms',
                ], [
                    'opted_out' => true,
                    'source' => 'inbound_sms',
                    'reason' => $normalizedBody,
                    'last_changed_at' => now(),
                ]);
            } elseif ($this->matchesKeyword($normalizedBody, self::SMS_OPT_IN_KEYWORDS)) {
                MessagingOptOut::query()->updateOrCreate([
                    'tenant_id' => $provider->tenant_id,
                    'phone' => $from,
                    'channel' => 'sms',
                ], [
                    'opted_out' => false,
                    'source' => 'inbound_sms',
                    'reason' => $normalizedBody,
                    'last_changed_at' => now(),
                ]);
            }
        }

        $thread = MessageThread::query()->firstOrCreate(
            [
                'tenant_id' => $provider->tenant_id,
                'channel' => $channel,
                'counterparty_number' => $from,
            ],
            [
                'status' => 'open',
                'priority' => 'normal',
            ]
        );
        $thread->last_message_at = now();
        if (! $thread->first_inbound_at) {
            $thread->first_inbound_at = now();
            $sla = $this->resolveInboxSlaPolicy($provider->tenant_id);
            if (($sla['enabled'] ?? true) === true) {
                $firstResponseMinutes = (int) ($sla['first_response_minutes'] ?? 60);
                $resolutionMinutes = (int) ($sla['resolution_minutes'] ?? 1440);
                $thread->first_response_due_at = now()->addMinutes(max(1, $firstResponseMinutes));
                $thread->resolution_due_at = now()->addMinutes(max(1, $resolutionMinutes));
            }
        }
        $thread->save();

        $message = Message::query()->create([
            'tenant_id' => $provider->tenant_id,
            'thread_id' => $thread->id,
            'direction' => 'inbound',
            'status' => 'received',
            'body' => $body,
            'provider_message_id' => (string) ($payload['MessageSid'] ?? ''),
            'metadata' => [
                'channel' => $channel,
                'from' => $from,
                'to' => $to,
                'payload' => $payload,
            ],
            'sent_at' => now(),
            'delivered_at' => now(),
        ]);

        $attachments = $this->mediaAttachmentService->ingestTwilioInboundMedia($provider, $message, $payload);
        if ($attachments !== []) {
            $message->metadata = array_merge((array) ($message->metadata ?? []), [
                'attachment_ids' => collect($attachments)->map(fn (MessageAttachment $att) => $att->id)->values()->all(),
            ]);
            $message->save();
        }

        $lead = Lead::query()
            ->where('tenant_id', $provider->tenant_id)
            ->where('phone', $from)
            ->first();
        if ($lead) {
            $this->appendTimelineMessage(
                tenantId: $provider->tenant_id,
                lead: $lead,
                message: $message,
                channel: $channel,
                direction: 'inbound',
                actorId: null
            );
        }

        return response()->json(['received' => true], 200);
    }

    private function appendTimelineMessage(
        string $tenantId,
        Lead $lead,
        Message $message,
        string $channel,
        string $direction,
        ?string $actorId
    ): void {
        LeadTimelineItem::query()->create([
            'tenant_id' => $tenantId,
            'lead_id' => $lead->id,
            'event_type' => $channel,
            'related_id' => $message->id,
            'related_type' => 'message',
            'actor_id' => $actorId,
            'content' => $message->body,
            'metadata' => [
                'channel' => $channel,
                'direction' => $direction,
                'status' => $message->status,
            ],
            'occurred_at' => now(),
        ]);
    }

    private function resolveTwilioProvider(string $accountSid): ?ProviderAccount
    {
        if ($accountSid === '') {
            return null;
        }

        $providers = ProviderAccount::query()
            ->where('provider_type', 'twilio')
            ->where('status', 'active')
            ->get();

        foreach ($providers as $provider) {
            $credentials = (array) $provider->credentials_encrypted;
            if (($credentials['account_sid'] ?? null) === $accountSid) {
                return $provider;
            }
        }

        return null;
    }

    private function resolveMetaWhatsappProviderByVerifyToken(string $verifyToken): ?ProviderAccount
    {
        if ($verifyToken === '') {
            return null;
        }

        $providers = ProviderAccount::query()
            ->where('provider_type', 'meta_whatsapp')
            ->latest('created_at')
            ->limit(50)
            ->get();

        foreach ($providers as $provider) {
            $credentials = (array) ($provider->credentials_encrypted ?? []);
            if ((string) ($credentials['webhook_verify_token'] ?? '') === $verifyToken) {
                return $provider;
            }
        }

        return null;
    }

    private function resolveMetaWhatsappProviderByPhoneNumberId(string $phoneNumberId): ?ProviderAccount
    {
        if ($phoneNumberId === '') {
            return null;
        }

        $providers = ProviderAccount::query()
            ->where('provider_type', 'meta_whatsapp')
            ->latest('created_at')
            ->limit(50)
            ->get();

        foreach ($providers as $provider) {
            $credentials = (array) ($provider->credentials_encrypted ?? []);
            if ((string) ($credentials['phone_number_id'] ?? '') === $phoneNumberId) {
                return $provider;
            }
        }

        return null;
    }

    private function normalizePhone(string $phone): string
    {
        return Str::startsWith($phone, 'whatsapp:')
            ? substr($phone, 9)
            : $phone;
    }

    private function isOptedOut(string $tenantId, string $phone, string $channel): bool
    {
        if ($phone === '') {
            return false;
        }

        return MessagingOptOut::query()
            ->where('tenant_id', $tenantId)
            ->where('phone', $phone)
            ->where('channel', $channel)
            ->where('opted_out', true)
            ->exists();
    }

    private function matchesKeyword(string $body, array $keywords): bool
    {
        if ($body === '') {
            return false;
        }

        foreach ($keywords as $keyword) {
            if ($body === $keyword) {
                return true;
            }
        }

        return false;
    }

    private function resolveInboxSlaPolicy(string $tenantId): array
    {
        $setting = TenantSetting::query()->where('tenant_id', $tenantId)->first();
        $metadata = (array) ($setting?->metadata ?? []);
        $policy = (array) ($metadata['inbox_sla'] ?? []);

        return array_merge([
            'enabled' => true,
            'first_response_minutes' => 60,
            'resolution_minutes' => 1440,
        ], $policy);
    }
}
