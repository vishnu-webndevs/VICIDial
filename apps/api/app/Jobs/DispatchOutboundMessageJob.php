<?php

namespace App\Jobs;

use App\Models\Lead;
use App\Models\LeadTimelineItem;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\MessageTemplate;
use App\Models\MessagingOptOut;
use App\Models\ProviderAccount;
use App\Models\CampaignRun;
use App\Models\Campaign;
use App\Models\MetaWhatsappTemplate;
use App\Services\Messaging\MetaTemplateService;
use App\Services\Messaging\MessageTemplateRenderer;
use App\Services\Messaging\SmsService;
use App\Services\Messaging\WhatsAppService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DispatchOutboundMessageJob implements ShouldQueue
{
    use Queueable;
    use InteractsWithQueue;

    public int $tries = 50;

    public int $timeout = 30;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $leadId,
        public readonly string $channel,
        public readonly string $content,
        public readonly ?string $templateKey = null,
        public readonly array $variables = [],
        public readonly ?string $sentByUserId = null,
        public readonly ?string $bulkBatchId = null,
        public readonly ?string $providerAccountId = null,
        public readonly ?string $campaignId = null,
        public readonly ?string $campaignRunId = null,
        public readonly bool $useMetaTemplate = false,
        public readonly ?string $metaTemplateId = null,
    ) {}

    public function handle(
        SmsService $smsService,
        WhatsAppService $whatsAppService,
        MessageTemplateRenderer $templateRenderer,
    ): void {
        $metaTemplateService = app(MetaTemplateService::class);
        $tenantId = $this->tenantId;
        $lead = Lead::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $this->leadId)
            ->first();
        if (! $lead) {
            Log::info('Outbound message dispatch skipped.', [
                'tenant_id' => $tenantId,
                'lead_id' => $this->leadId,
                'channel' => $this->channel,
                'bulk_batch_id' => $this->bulkBatchId,
                'campaign_id' => $this->campaignId,
                'campaign_run_id' => $this->campaignRunId,
                'reason' => 'lead_not_found',
            ]);

            return;
        }

        $channel = $this->channel;
        if (! in_array($channel, ['sms', 'whatsapp'], true)) {
            Log::info('Outbound message dispatch skipped.', [
                'tenant_id' => $tenantId,
                'lead_id' => $lead->id,
                'channel' => $channel,
                'bulk_batch_id' => $this->bulkBatchId,
                'campaign_id' => $this->campaignId,
                'campaign_run_id' => $this->campaignRunId,
                'reason' => 'invalid_channel',
            ]);

            return;
        }

        if ($channel === 'sms' && $this->isOptedOut($tenantId, (string) $lead->phone, 'sms')) {
            Log::info('Outbound message dispatch skipped.', [
                'tenant_id' => $tenantId,
                'lead_id' => $lead->id,
                'channel' => $channel,
                'to' => $this->maskPhone((string) $lead->phone),
                'bulk_batch_id' => $this->bulkBatchId,
                'campaign_id' => $this->campaignId,
                'campaign_run_id' => $this->campaignRunId,
                'reason' => 'opted_out',
            ]);

            return;
        }

        $this->logEvent('info', 'Outbound message dispatch hit.', [
            'tenant_id' => $tenantId,
            'lead_id' => $lead->id,
            'channel' => $channel,
            'to' => $this->maskPhone((string) $lead->phone),
            'template_key' => $this->templateKey !== '' ? $this->templateKey : null,
            'meta_template_id' => $this->metaTemplateId,
            'bulk_batch_id' => $this->bulkBatchId,
            'provider_account_id' => $this->providerAccountId,
            'campaign_id' => $this->campaignId,
            'campaign_run_id' => $this->campaignRunId,
        ]);

        $content = trim($this->content);
        $templateKey = trim($this->templateKey);
        $metaTemplateId = $this->metaTemplateId;
        $bodyOrPayload = $content;

        if ($metaTemplateId && $channel === 'whatsapp') {
            $metaTemplate = MetaWhatsappTemplate::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $metaTemplateId)
                ->first();
            if ($metaTemplate) {
                // Load campaign for media settings if available
                $campaignMediaUrl = null;
                if ($this->campaignId) {
                    $campaign = Campaign::find($this->campaignId);
                    $campaignMediaUrl = $campaign?->settings['message_media_url'] ?? null;
                }

                // Prepare lead variables (same as manual templates)
                $fullName = trim((string) ($lead->full_name ?? ''));
                $parts = [];
                if ($fullName !== '') {
                    $parts = preg_split('/\s+/', $fullName) ?: [];
                }
                $firstName = (string) ($parts[0] ?? '');
                $lastName = count($parts) > 1 ? (string) end($parts) : '';

                $variables = array_merge([
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'company_name' => (string) ($lead->company ?? ''),
                    'phone' => (string) ($lead->phone ?? ''),
                    'email' => (string) ($lead->email ?? ''),
                    'campaign_media_url' => $campaignMediaUrl,
                    'lead' => [
                        'id' => $lead->id,
                        'full_name' => $lead->full_name,
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                        'phone' => $lead->phone,
                        'email' => $lead->email,
                        'company' => $lead->company,
                    ],
                ], $this->variables);

                $bodyOrPayload = $metaTemplateService->buildTemplatePayload($metaTemplate, (string) $lead->phone, $variables);
                $content = $metaTemplateService->buildTemplateTextPreview($metaTemplate, $variables);
            } else {
                Log::warning('Meta template not found.', [
                    'tenant_id' => $tenantId,
                    'meta_template_id' => $metaTemplateId,
                ]);
            }
        } elseif ($templateKey !== '') {
            $template = MessageTemplate::query()
                ->where('tenant_id', $tenantId)
                ->where('channel', $channel)
                ->where('key', $templateKey)
                ->where('is_active', true)
                ->first();
            if (! $template) {
                Log::info('Outbound message dispatch skipped.', [
                    'tenant_id' => $tenantId,
                    'lead_id' => $lead->id,
                    'channel' => $channel,
                    'to' => $this->maskPhone((string) $lead->phone),
                    'template_key' => $templateKey,
                    'bulk_batch_id' => $this->bulkBatchId,
                    'campaign_id' => $this->campaignId,
                    'campaign_run_id' => $this->campaignRunId,
                    'reason' => 'template_not_found_or_inactive',
                ]);

                return;
            }

            $fullName = trim((string) ($lead->full_name ?? ''));
            $parts = [];
            if ($fullName !== '') {
                $parts = preg_split('/\s+/', $fullName) ?: [];
            }
            $firstName = (string) ($parts[0] ?? '');
            $lastName = count($parts) > 1 ? (string) end($parts) : '';

            $variables = array_merge([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'company_name' => (string) ($lead->company ?? ''),
                'phone' => (string) ($lead->phone ?? ''),
                'email' => (string) ($lead->email ?? ''),
                'campaign_name' => '',
                'agent_name' => '',
                'lead' => [
                    'id' => $lead->id,
                    'full_name' => $lead->full_name,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'phone' => $lead->phone,
                    'email' => $lead->email,
                    'company' => $lead->company,
                ],
            ], $this->variables);

            $content = trim($templateRenderer->render((string) $template->body, $variables));
            $bodyOrPayload = $content;
            if ($content === '') {
                Log::info('Outbound message dispatch skipped.', [
                    'tenant_id' => $tenantId,
                    'lead_id' => $lead->id,
                    'channel' => $channel,
                    'to' => $this->maskPhone((string) $lead->phone),
                    'template_key' => $templateKey,
                    'bulk_batch_id' => $this->bulkBatchId,
                    'campaign_id' => $this->campaignId,
                    'campaign_run_id' => $this->campaignRunId,
                    'reason' => 'rendered_content_empty',
                ]);

                return;
            }
        }

        // Check Global Tenant Calling Window
        $tenantSetting = \App\Models\TenantSetting::query()
            ->where('tenant_id', $tenantId)
            ->first();

        if ($tenantSetting) {
            $metadata = (array) ($tenantSetting->metadata ?? []);
            $callingWindow = (array) ($metadata['calling_window'] ?? []);
            if ($callingWindow !== []) {
                $days = (array) ($callingWindow['days'] ?? []);
                $start = (string) ($callingWindow['start_time'] ?? '');
                $end = (string) ($callingWindow['end_time'] ?? '');
                $timezone = (string) ($callingWindow['timezone'] ?? '') ?: $tenantSetting->timezone ?: 'UTC';

                try {
                    $now = \Illuminate\Support\Carbon::now($timezone);
                } catch (\Throwable) {
                    $now = \Illuminate\Support\Carbon::now('UTC');
                }

                // Check days
                if ($days !== []) {
                    $currentDay = $now->format('D'); // Mon, Tue, etc.
                    if (!in_array($currentDay, $days, true)) {
                        Log::info('Outbound message dispatch delayed (outside global tenant days window).', [
                            'tenant_id' => $tenantId,
                            'lead_id' => $lead->id,
                            'timezone' => $timezone,
                        ]);
                        $this->release(300); // Try again in 5 minutes
                        return;
                    }
                }

                // Check time
                if ($start !== '' && $end !== '') {
                    $currentTime = $now->format('H:i');
                    if ($currentTime < $start || $currentTime > $end) {
                        Log::info('Outbound message dispatch delayed (outside global tenant hours window).', [
                            'tenant_id' => $tenantId,
                            'lead_id' => $lead->id,
                            'current_time' => $currentTime,
                            'start' => $start,
                            'end' => $end,
                            'timezone' => $timezone,
                        ]);
                        $this->release(300); // Try again in 5 minutes
                        return;
                    }
                }
            }
        }

        if ($this->campaignId) {
            $campaign = Campaign::find($this->campaignId);
            if ($campaign && !$campaign->isWithinScheduleWindow()) {
                Log::info('Outbound message dispatch delayed (outside campaign schedule window).', [
                    'tenant_id' => $tenantId,
                    'lead_id' => $lead->id,
                    'channel' => $channel,
                    'to' => $this->maskPhone((string) $lead->phone),
                    'bulk_batch_id' => $this->bulkBatchId,
                    'campaign_id' => $this->campaignId,
                    'campaign_run_id' => $this->campaignRunId,
                    'reason' => 'outside_schedule_window',
                    'schedule_window' => $campaign->schedule_window,
                ]);

                $this->release(60);
                return;
            }
        }

        if ($this->campaignRunId) {
            $run = CampaignRun::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $this->campaignRunId)
                ->first();
            if ($run && $run->status !== 'running') {
                if (in_array($run->status, ['paused', 'queued'], true)) {
                    Log::info('Outbound message dispatch delayed (campaign run not running).', [
                        'tenant_id' => $tenantId,
                        'lead_id' => $lead->id,
                        'channel' => $channel,
                        'to' => $this->maskPhone((string) $lead->phone),
                        'bulk_batch_id' => $this->bulkBatchId,
                        'campaign_id' => $this->campaignId,
                        'campaign_run_id' => $this->campaignRunId,
                        'reason' => 'campaign_run_not_running',
                        'run_status' => $run->status,
                    ]);

                    $this->release(60);
                    return;
                }

                Log::info('Outbound message dispatch skipped.', [
                    'tenant_id' => $tenantId,
                    'lead_id' => $lead->id,
                    'channel' => $channel,
                    'to' => $this->maskPhone((string) $lead->phone),
                    'bulk_batch_id' => $this->bulkBatchId,
                    'campaign_id' => $this->campaignId,
                    'campaign_run_id' => $this->campaignRunId,
                    'reason' => 'campaign_run_not_running',
                    'run_status' => $run->status,
                ]);

                return;
            }
        }

        $providerCredentials = null;
        if ($this->providerAccountId) {
            $provider = ProviderAccount::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $this->providerAccountId)
                ->where('status', 'active')
                ->first();
            if (! $provider) {
                $result = [
                    'ok' => false,
                    'error' => 'Selected provider is missing or inactive.',
                    'status_code' => 422,
                ];
            } else {
                $providerCredentials = (array) $provider->credentials_encrypted;
            }
        }

        $statusCallbackUrl = rtrim((string) config('app.url'), '/') . '/api/v1/webhooks/twilio/message-status';
        $result = $result ?? ($channel === 'sms'
            ? $smsService->send((string) $lead->phone, $content, $statusCallbackUrl, $providerCredentials)
            : $whatsAppService->send((string) $lead->phone, $bodyOrPayload, $statusCallbackUrl, $providerCredentials));

        if (($result['ok'] ?? false) !== true) {
            Log::error('Outbound message delivery failed.', [
                'tenant_id' => $tenantId,
                'lead_id' => $lead->id,
                'channel' => $channel,
                'error' => $result['error'] ?? 'Unknown error',
                'status_code' => $result['status_code'] ?? null,
                'provider_account_id' => $this->providerAccountId,
            ]);

            $thread = MessageThread::query()->firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'channel' => $channel,
                    'counterparty_number' => $lead->phone,
                ],
                [
                    'contact_id' => null,
                    'project_id' => null,
                    'assigned_user_id' => $this->sentByUserId,
                    'status' => 'open',
                    'priority' => 'normal',
                ]
            );
            $thread->last_message_at = now();
            $thread->save();

            $errorMessage = (string) ($result['error'] ?? 'Message delivery failed.');
            Message::query()->create([
                'tenant_id' => $tenantId,
                'thread_id' => $thread->id,
                'direction' => 'outbound',
                'status' => 'failed',
                'body' => $content,
                'sent_by_user_id' => $this->sentByUserId,
                'provider_message_id' => (string) ($result['provider_message_id'] ?? ''),
                'metadata' => [
                    'channel' => $channel,
                    'template_key' => $templateKey !== '' ? $templateKey : null,
                    'bulk_batch_id' => $this->bulkBatchId,
                    'provider_account_id' => $this->providerAccountId,
                    'campaign_id' => $this->campaignId,
                    'campaign_run_id' => $this->campaignRunId,
                    'error' => $errorMessage,
                    'status_code' => $result['status_code'] ?? null,
                ],
                'sent_at' => now(),
            ]);

            LeadTimelineItem::query()->create([
                'tenant_id' => $tenantId,
                'lead_id' => $lead->id,
                'event_type' => $channel,
                'related_id' => null,
                'related_type' => 'message',
                'actor_id' => $this->sentByUserId,
                'content' => $content,
                'metadata' => [
                    'channel' => $channel,
                    'direction' => 'outbound',
                    'status' => 'failed',
                    'bulk_batch_id' => $this->bulkBatchId,
                    'campaign_id' => $this->campaignId,
                    'campaign_run_id' => $this->campaignRunId,
                    'error' => $errorMessage,
                ],
                'occurred_at' => now(),
            ]);

            $this->logEvent('warning', 'Outbound message dispatch failed.', [
                'tenant_id' => $tenantId,
                'lead_id' => $lead->id,
                'channel' => $channel,
                'to' => $this->maskPhone((string) $lead->phone),
                'provider_account_id' => $this->providerAccountId,
                'campaign_id' => $this->campaignId,
                'campaign_run_id' => $this->campaignRunId,
                'bulk_batch_id' => $this->bulkBatchId,
                'provider_message_id' => (string) ($result['provider_message_id'] ?? ''),
                'error' => $errorMessage,
                'status_code' => $result['status_code'] ?? null,
            ]);

            $this->markCampaignRunItemResult($tenantId, $this->campaignRunId, $this->campaignId, false);

            return;
        }

        $thread = MessageThread::query()->firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'channel' => $channel,
                'counterparty_number' => $lead->phone,
            ],
            [
                'contact_id' => null,
                'project_id' => null,
                'assigned_user_id' => $this->sentByUserId,
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
            'tenant_id' => $tenantId,
            'thread_id' => $thread->id,
            'direction' => 'outbound',
            'status' => (string) ($result['status'] ?? 'queued'),
            'body' => $content,
            'sent_by_user_id' => $this->sentByUserId,
            'provider_message_id' => (string) ($result['provider_message_id'] ?? ''),
            'metadata' => [
                'channel' => $channel,
                'template_key' => $templateKey !== '' ? $templateKey : null,
                'bulk_batch_id' => $this->bulkBatchId,
                'provider_account_id' => $this->providerAccountId,
                'campaign_id' => $this->campaignId,
                'campaign_run_id' => $this->campaignRunId,
                'meta_template_id' => $this->metaTemplateId,
            ],
            'sent_at' => now(),
        ]);

        LeadTimelineItem::query()->create([
            'tenant_id' => $tenantId,
            'lead_id' => $lead->id,
            'event_type' => $channel,
            'related_id' => $message->id,
            'related_type' => 'message',
            'actor_id' => $this->sentByUserId,
            'content' => $message->body,
            'metadata' => [
                'channel' => $channel,
                'direction' => 'outbound',
                'status' => $message->status,
                'bulk_batch_id' => $this->bulkBatchId,
                'campaign_id' => $this->campaignId,
                'campaign_run_id' => $this->campaignRunId,
            ],
            'occurred_at' => now(),
        ]);

        $this->logEvent('info', 'Outbound message dispatched.', [
            'tenant_id' => $tenantId,
            'message_id' => $message->id,
            'lead_id' => $lead->id,
            'channel' => $channel,
            'to' => $this->maskPhone((string) $lead->phone),
            'status' => $message->status,
            'provider_account_id' => $this->providerAccountId,
            'provider_message_id' => $message->provider_message_id,
            'bulk_batch_id' => $this->bulkBatchId,
            'campaign_id' => $this->campaignId,
            'campaign_run_id' => $this->campaignRunId,
        ]);

        $this->markCampaignRunItemResult($tenantId, $this->campaignRunId, $this->campaignId, true);
    }

    private function markCampaignRunItemResult(string $tenantId, ?string $campaignRunId, ?string $campaignId, bool $success): void
    {
        if (! $campaignRunId) {
            return;
        }

        DB::transaction(function () use ($tenantId, $campaignRunId, $campaignId, $success): void {
            $run = CampaignRun::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $campaignRunId)
                ->lockForUpdate()
                ->first();
            if (! $run) {
                return;
            }

            $run->queued_items = max(0, (int) ($run->queued_items ?? 0) - 1);
            if ($success) {
                $run->completed_items = max(0, (int) ($run->completed_items ?? 0)) + 1;
            } else {
                $run->failed_items = max(0, (int) ($run->failed_items ?? 0)) + 1;
            }
            $run->last_tick_at = now();

            if ($run->queued_items === 0 && $run->status === 'running') {
                try {
                    $this->logEvent('info', 'Campaign run completing.', [
                        'tenant_id' => $tenantId,
                        'campaign_id' => $campaignId ?: (string) $run->campaign_id,
                        'campaign_run_id' => $run->id,
                        'pending_items' => 0,
                        'processing_items' => 0,
                        'dialed_items' => 0,
                        'completed_items' => (int) $run->completed_items,
                        'failed_items' => (int) $run->failed_items,
                    ]);
                } catch (\Throwable) {
                }

                $run->status = 'completed';
                $run->stopped_at = now();
            }

            $run->save();

            if ($run->status === 'completed') {
                $id = $campaignId ?: (string) $run->campaign_id;
                if ($id !== '') {
                    Campaign::query()
                        ->where('tenant_id', $tenantId)
                        ->where('id', $id)
                        ->update(['status' => 'completed']);
                }
            }
        });
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

    private function maskPhone(string $phone): string
    {
        $trimmed = trim($phone);
        if ($trimmed === '') {
            return '';
        }

        $digits = preg_replace('/\D+/', '', $trimmed) ?? '';
        if (strlen($digits) <= 4) {
            return str_repeat('*', max(0, strlen($digits)));
        }

        return '+' . str_repeat('*', max(0, strlen($digits) - 4)) . substr($digits, -4);
    }

    private function logEvent(string $level, string $message, array $context): void
    {
        try {
            Log::log($level, $message, $context);
        } catch (\Throwable) {
        }

        try {
            $line = sprintf(
                "[%s] %s: %s %s\n",
                now()->format('Y-m-d H:i:s'),
                strtoupper($level),
                $message,
                json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
            @file_put_contents(storage_path('logs/laravel.log'), $line, FILE_APPEND);
        } catch (\Throwable) {
        }
    }
}
