<?php

namespace App\Services\Campaigns;

use App\Jobs\DispatchOutboundCallJob;
use App\Models\CallEvent;
use App\Models\CallSession;
use App\Models\Campaign;
use App\Models\AgentPhoneAssignment;
use App\Models\CampaignAgentAssignment;
use App\Models\DialQueueItem;
use App\Models\Lead;
use App\Models\ProviderAccount;
use App\Models\ProviderPhoneNumber;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class OutboundDialerService
{
    /**
     * @return array{ok: bool, call?: CallSession, error?: string}
     */
    public function dialQueueItem(
        Campaign $campaign,
        DialQueueItem $queueItem,
        Lead $lead,
        ?string $agentId = null
    ): array {
        $providers = $this->resolveProviders($campaign);
        if ($providers->isEmpty()) {
            return ['ok' => false, 'error' => 'No active provider account is available for this tenant.'];
        }

        foreach ($providers as $provider) {
            $fromNumber = $this->resolveFromNumberForAgent(
                tenantId: $campaign->tenant_id,
                campaignId: $campaign->id,
                agentId: $agentId,
                providerId: $provider->id
            ) ?: (string) ($provider->credentials_encrypted['from_number'] ?? '');
            if ($fromNumber === '') {
                continue;
            }

            $call = CallSession::query()->create([
                'tenant_id' => $campaign->tenant_id,
                'provider_account_id' => $provider->id,
                'initiated_by' => null,
                'direction' => 'outbound',
                'status' => 'queued',
                'provider_call_id' => 'call_'.Str::lower(Str::random(24)),
                'from_number' => $fromNumber,
                'to_number' => $lead->phone,
                'retry_count' => max(0, $queueItem->attempt_count - 1),
                'metadata' => [
                    'campaign_id' => $campaign->id,
                    'queue_item_id' => $queueItem->id,
                    'lead_id' => $lead->id,
                    'agent_id' => $agentId,
                    'dial_mode' => 'normal',
                    'twiml_token' => Str::random(40),
                    'controls' => ['muted' => false, 'on_hold' => false],
                ],
            ]);

            CallEvent::query()->create([
                'tenant_id' => $campaign->tenant_id,
                'call_session_id' => $call->id,
                'provider_account_id' => $provider->id,
                'event_type' => 'call.initiated',
                'provider_event_type' => 'outbound.auto_dial',
                'status_after' => $call->status,
                'payload' => [
                    'campaign_id' => $campaign->id,
                    'queue_item_id' => $queueItem->id,
                    'provider_id' => $provider->id,
                ],
                'occurred_at' => now(),
            ]);

            // Dispatch the actual outbound call asynchronously via a queued job.
            $baseUrl = rtrim((string) config('app.url'), '/');
            $metadata = (array) ($call->metadata ?? []);
            $twimlToken = (string) ($metadata['twiml_token'] ?? '');
            $dialMode = (string) ($metadata['dial_mode'] ?? '');
            $dialQuery = $dialMode !== '' ? '&dial_mode='.urlencode($dialMode) : '';
            $scriptUrl = $provider->provider_type === 'vonage'
                ? $baseUrl.'/api/webhooks/vonage/ncco/outbound?call_session_id='.$call->id
                : $baseUrl.'/api/webhooks/twilio/twiml/outbound?call_session_id='.$call->id.'&token='.urlencode($twimlToken).$dialQuery;
            $statusCallbackUrl = $baseUrl.'/api/webhooks/'.$provider->provider_type;
            DispatchOutboundCallJob::dispatch(
                callSessionId: $call->id,
                providerAccountId: $provider->id,
                twimlUrl: $scriptUrl,
                statusCallbackUrl: $statusCallbackUrl,
            );

            return ['ok' => true, 'call' => $call];
        }

        return ['ok' => false, 'error' => 'Active providers do not have valid outbound caller IDs configured.'];
    }

    /**
     * @return Collection<int, ProviderAccount>
     */
    private function resolveProviders(Campaign $campaign): Collection
    {
        $providers = ProviderAccount::query()
            ->where('tenant_id', $campaign->tenant_id)
            ->where('status', 'active')
            ->orderByDesc('created_at')
            ->get();

        if ($campaign->preferred_provider_account_id === null) {
            return $providers;
        }

        $preferred = $providers->firstWhere('id', $campaign->preferred_provider_account_id);
        if (! $preferred) {
            return $providers;
        }

        return collect([$preferred])->concat($providers->reject(
            fn (ProviderAccount $item) => $item->id === $preferred->id
        ))->values();
    }

    private function resolveFromNumberForAgent(
        string $tenantId,
        string $campaignId,
        ?string $agentId,
        string $providerId
    ): ?string {
        if (! $agentId) {
            return null;
        }

        $numberId = CampaignAgentAssignment::query()
            ->where('tenant_id', $tenantId)
            ->where('campaign_id', $campaignId)
            ->where('agent_id', $agentId)
            ->value('provider_phone_number_id');

        if (! $numberId) {
            $numberId = AgentPhoneAssignment::query()
                ->where('tenant_id', $tenantId)
                ->where('agent_id', $agentId)
                ->where('status', 'active')
                ->value('provider_phone_number_id');
        }

        if (! $numberId) {
            return null;
        }

        $number = ProviderPhoneNumber::query()
            ->where('tenant_id', $tenantId)
            ->where('provider_account_id', $providerId)
            ->where('id', $numberId)
            ->where('status', 'active')
            ->where('is_validated', true)
            ->first();

        return $number?->phone_number;
    }
}
