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
use App\Services\Messaging\MessageTemplateRenderer;
use App\Services\Messaging\SmsService;
use App\Services\Messaging\WhatsAppService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class DispatchOutboundMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $leadId,
        public readonly string $channel,
        public readonly string $content,
        public readonly string $templateKey,
        public readonly array $variables,
        public readonly ?string $sentByUserId,
        public readonly string $bulkBatchId,
        public readonly ?string $providerAccountId = null,
        public readonly ?string $campaignId = null,
        public readonly ?string $campaignRunId = null,
    ) {
    }

    public function handle(
        SmsService $smsService,
        WhatsAppService $whatsAppService,
        MessageTemplateRenderer $templateRenderer,
    ): void {
        $tenantId = $this->tenantId;
        $lead = Lead::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $this->leadId)
            ->first();
        if (! $lead) {
            return;
        }

        $channel = $this->channel;
        if (! in_array($channel, ['sms', 'whatsapp'], true)) {
            return;
        }

        if ($channel === 'sms' && $this->isOptedOut($tenantId, (string) $lead->phone, 'sms')) {
            return;
        }

        $content = trim($this->content);
        $templateKey = trim($this->templateKey);
        if ($templateKey !== '') {
            $template = MessageTemplate::query()
                ->where('tenant_id', $tenantId)
                ->where('channel', $channel)
                ->where('key', $templateKey)
                ->where('is_active', true)
                ->first();
            if (! $template) {
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
            if ($content === '') {
                return;
            }
        }

        if ($this->campaignRunId) {
            $run = CampaignRun::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $this->campaignRunId)
                ->first();
            if ($run && $run->status !== 'running') {
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

        $statusCallbackUrl = rtrim((string) config('app.url'), '/').'/api/v1/webhooks/twilio/message-status';
        $result = $result ?? ($channel === 'sms'
            ? $smsService->send((string) $lead->phone, $content, $statusCallbackUrl, $providerCredentials)
            : $whatsAppService->send((string) $lead->phone, $content, $statusCallbackUrl, $providerCredentials));

        if (($result['ok'] ?? false) !== true) {
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

            Log::warning('Outbound message dispatch failed.', [
                'tenant_id' => $tenantId,
                'lead_id' => $lead->id,
                'channel' => $channel,
                'provider_account_id' => $this->providerAccountId,
                'campaign_id' => $this->campaignId,
                'campaign_run_id' => $this->campaignRunId,
                'error' => $errorMessage,
                'status_code' => $result['status_code'] ?? null,
            ]);

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
            ],
            'occurred_at' => now(),
        ]);
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
}
