<?php

namespace App\Services\Campaigns;

use App\Models\AgentAssignment;
use App\Models\Agent;
use App\Models\AgentPhoneAssignment;
use App\Models\AgentSession;
use App\Models\CallSession;
use App\Models\Campaign;
use App\Models\CampaignAgentAssignment;
use App\Models\CampaignRun;
use App\Models\DialQueueItem;
use App\Models\Lead;
use App\Models\Membership;
use App\Models\Notification;
use App\Models\ProviderAccount;
use App\Models\ProviderPhoneNumber;
use App\Models\TenantSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CampaignRunnerService
{
    private const RETRYABLE_STATUSES = ['failed', 'busy', 'no_answer', 'timeout', 'canceled'];
    private const STALE_LIVE_CALL_SECONDS = 600;

    public function __construct(
        private readonly OutboundDialerService $dialerService
    ) {
    }

    public function tick(CampaignRun $run): void
    {
        $run->loadMissing('campaign');
        $campaign = $run->campaign;

        // #region debug-point A:tick-entry
        $this->debugReport('A', 'campaign.tick.entry', [
            'campaign_run_id' => (string) $run->id,
            'run_status' => (string) $run->status,
            'campaign_id' => (string) ($campaign?->id ?? ''),
            'campaign_status' => (string) ($campaign?->status ?? ''),
            'tenant_id' => (string) ($campaign?->tenant_id ?? ''),
            'campaign_type' => (string) ($campaign?->type ?? ''),
        ]);
        // #endregion

        if (! $campaign || $run->status !== 'running' || $campaign->status !== 'running') {
            $this->writeCallCampaignLog('info', 'Call campaign tick skipped (not running).', [
                'campaign_run_id' => $run->id,
                'run_status' => $run->status,
                'campaign_id' => (string) ($campaign?->id ?? ''),
                'campaign_status' => (string) ($campaign?->status ?? ''),
            ]);
            return;
        }

        if (in_array($campaign->type, ['sms', 'whatsapp', 'outreach'], true)) {
            return;
        }

        $this->writeCallCampaignLog('info', 'Call campaign tick hit.', [
            'tenant_id' => $campaign->tenant_id,
            'campaign_id' => $campaign->id,
            'campaign_run_id' => $run->id,
            'calls_per_minute' => (int) ($campaign->calls_per_minute ?? 0),
            'queue_size' => (int) ($campaign->queue_size ?? 0),
        ]);

        $this->refreshDialedQueueItems($run, $campaign);
        $run->refresh();
        $campaign->refresh();
        if ($run->status !== 'running' || $campaign->status !== 'running') {
            $this->writeCallCampaignLog('info', 'Call campaign tick stopped after refresh.', [
                'tenant_id' => $campaign->tenant_id,
                'campaign_id' => $campaign->id,
                'campaign_run_id' => $run->id,
                'run_status' => $run->status,
                'campaign_status' => $campaign->status,
            ]);
            return;
        }

        if (! $this->isWithinAllowedCallingWindow($campaign)) {
            // #region debug-point C:outside-window
            $this->debugReport('C', 'campaign.tick.outside_calling_window', [
                'tenant_id' => (string) $campaign->tenant_id,
                'campaign_id' => (string) $campaign->id,
                'campaign_run_id' => (string) $run->id,
            ]);
            // #endregion

            $this->writeCallCampaignLog('info', 'Call campaign waiting (outside calling window).', [
                'tenant_id' => $campaign->tenant_id,
                'campaign_id' => $campaign->id,
                'campaign_run_id' => $run->id,
            ]);

            return;
        }

        $dispatchable = $this->availableDispatchSlots($run, $campaign);
        if ($dispatchable <= 0) {
            // #region debug-point B:no-dispatch-slots
            $this->debugReport('B', 'campaign.tick.no_dispatch_slots', [
                'tenant_id' => (string) $campaign->tenant_id,
                'campaign_id' => (string) $campaign->id,
                'campaign_run_id' => (string) $run->id,
                'calls_dispatched_in_window' => (int) $run->calls_dispatched_in_window,
                'pacing_window_started_at' => $run->pacing_window_started_at?->toISOString(),
            ]);
            // #endregion

            $this->writeCallCampaignLog('info', 'Call campaign tick no dispatch slots.', [
                'tenant_id' => $campaign->tenant_id,
                'campaign_id' => $campaign->id,
                'campaign_run_id' => $run->id,
                'calls_dispatched_in_window' => (int) $run->calls_dispatched_in_window,
                'pacing_window_started_at' => $run->pacing_window_started_at?->toISOString(),
            ]);
            $run->last_tick_at = now();
            $run->save();

            return;
        }

        $agents = $this->availableAgentSessions($campaign->tenant_id);
        if ($agents->isEmpty()) {
            $this->ensureCampaignAgentSessions($campaign);
            $agents = $this->availableAgentSessions($campaign->tenant_id);
        }
        if ($agents->isEmpty()) {
            $debug = $this->buildNoAvailableAgentsDebug($campaign);

            // #region debug-point D:no-agents
            $this->debugReport('D', 'campaign.tick.no_available_agents', [
                'tenant_id' => (string) $campaign->tenant_id,
                'campaign_id' => (string) $campaign->id,
                'campaign_run_id' => (string) $run->id,
                'auto_pause_when_no_agents' => (bool) $campaign->auto_pause_when_no_agents,
                'mapped_agents_total' => (int) ($debug['mapped_agents_total'] ?? 0),
                'eligible_agents' => (int) ($debug['eligible_agents'] ?? 0),
                'online_agents' => (int) ($debug['online_agents'] ?? 0),
                'offline_agents' => (int) ($debug['offline_agents'] ?? 0),
                'mapping_last_updated_at' => $debug['mapping_last_updated_at'] ?? null,
            ]);
            // #endregion

            $this->writeCallCampaignLog('info', 'Call campaign tick no available agents.', [
                'tenant_id' => $campaign->tenant_id,
                'campaign_id' => $campaign->id,
                'campaign_run_id' => $run->id,
                'auto_pause_when_no_agents' => (bool) $campaign->auto_pause_when_no_agents,
                'mapped_agents_total' => (int) ($debug['mapped_agents_total'] ?? 0),
                'eligible_agents' => (int) ($debug['eligible_agents'] ?? 0),
                'online_agents' => (int) ($debug['online_agents'] ?? 0),
                'offline_agents' => (int) ($debug['offline_agents'] ?? 0),
                'mapping_last_updated_at' => $debug['mapping_last_updated_at'] ?? null,
                'agents' => $debug['agents'] ?? [],
            ]);

            if (
                $campaign->auto_pause_when_no_agents
                && (int) ($debug['mapped_agents_total'] ?? 0) === 0
            ) {
                $this->pauseCampaignRun($run, $campaign, 'no_agents_mapped');
                return;
            }

            if (
                $campaign->auto_pause_when_no_agents
                && (int) ($debug['mapped_agents_total'] ?? 0) > 0
                && (int) ($debug['online_agents'] ?? 0) === 0
            ) {
                $this->pauseCampaignRun($run, $campaign, 'no_available_agents');
            } else {
                $run->last_tick_at = now();
                $run->save();
            }

            return;
        }

        $queueItems = DialQueueItem::query()
            ->where('tenant_id', $campaign->tenant_id)
            ->where('campaign_id', $campaign->id)
            ->where('campaign_run_id', $run->id)
            ->where('status', 'pending')
            ->where(function ($query) {
                $query->whereNull('available_at')
                    ->orWhere('available_at', '<=', now());
            })
            ->orderByDesc('priority')
            ->orderBy('enqueued_at')
            ->limit($dispatchable)
            ->get();

        if ($queueItems->isEmpty()) {
            // #region debug-point E:no-queue-items
            $this->debugReport('E', 'campaign.tick.no_pending_queue_items', [
                'tenant_id' => (string) $campaign->tenant_id,
                'campaign_id' => (string) $campaign->id,
                'campaign_run_id' => (string) $run->id,
            ]);
            // #endregion

            $this->writeCallCampaignLog('info', 'Call campaign tick no pending queue items.', [
                'tenant_id' => $campaign->tenant_id,
                'campaign_id' => $campaign->id,
                'campaign_run_id' => $run->id,
            ]);
            $run->last_tick_at = now();
            $run->save();

            return;
        }

        $agentIndex = 0;
        $activeAgents = $agents->values();

        /** @var DialQueueItem $item */
        foreach ($queueItems as $item) {
            $agent = $activeAgents[$agentIndex % $activeAgents->count()];
            $agentIndex++;
            $lead = Lead::query()->where('tenant_id', $campaign->tenant_id)->find($item->lead_id);
            if (! $lead) {
                $item->status = 'failed';
                $item->failure_reason = 'Lead not found.';
                $item->processed_at = now();
                $item->save();
                continue;
            }
            if (! $this->isWithinAllowedCallingWindow($campaign, $lead)) {
                // #region debug-point C:item-outside-window
                $this->debugReport('C', 'campaign.queue_item.outside_calling_window', [
                    'tenant_id' => (string) $campaign->tenant_id,
                    'campaign_id' => (string) $campaign->id,
                    'campaign_run_id' => (string) $run->id,
                    'queue_item_id' => (string) $item->id,
                    'lead_id' => (string) $lead->id,
                ]);
                // #endregion

                $item->available_at = now()->addMinutes(15);
                $item->failure_reason = 'outside_allowed_calling_window';
                $item->save();
                continue;
            }

            DB::transaction(function () use ($campaign, $run, $item, $lead, $agent): void {
                $item->attempt_count = $item->attempt_count + 1;
                $item->status = 'processing';
                $item->assigned_agent_entity_id = $agent->agent_id;
                $item->save();

                $dialResult = $this->dialerService->dialQueueItem($campaign, $item, $lead, $agent->agent_id);
                // #region debug-point F:dial-result
                $this->debugReport('F', 'campaign.dial_queue_item.result', [
                    'tenant_id' => (string) $campaign->tenant_id,
                    'campaign_id' => (string) $campaign->id,
                    'campaign_run_id' => (string) $run->id,
                    'queue_item_id' => (string) $item->id,
                    'lead_id' => (string) $lead->id,
                    'agent_id' => (string) $agent->agent_id,
                    'ok' => (bool) ($dialResult['ok'] ?? false),
                    'error' => (string) ($dialResult['error'] ?? ''),
                    'call_session_id' => isset($dialResult['call']) ? (string) $dialResult['call']->id : null,
                ]);
                // #endregion

                if (($dialResult['ok'] ?? false) !== true || ! isset($dialResult['call'])) {
                    if ($item->attempt_count < $item->max_attempts) {
                        $item->status = 'pending';
                        $item->available_at = now()->addSeconds(30 * $item->attempt_count);
                    } else {
                        $item->status = 'failed';
                        $item->processed_at = now();
                    }
                    $item->failure_reason = (string) ($dialResult['error'] ?? 'Dial failed');
                    $item->save();

                    return;
                }

                /** @var CallSession $call */
                $call = $dialResult['call'];
                $item->status = 'dialed';
                $item->last_call_session_id = $call->id;
                $item->processed_at = now();
                $item->failure_reason = null;
                $item->save();

                AgentAssignment::query()->create([
                    'tenant_id' => $campaign->tenant_id,
                    'campaign_id' => $campaign->id,
                    'campaign_run_id' => $run->id,
                    'dial_queue_item_id' => $item->id,
                    'agent_id' => $agent->agent_id,
                    'agent_session_id' => $agent->id,
                    'status' => 'assigned',
                    'assigned_at' => now(),
                ]);

                $agent->active_assignments = max(0, (int) $agent->active_assignments) + 1;
                $agent->last_heartbeat_at = now();
                $agent->save();

                $run->calls_dispatched = $run->calls_dispatched + 1;
                $run->calls_dispatched_in_window = $run->calls_dispatched_in_window + 1;
                $run->save();
            });
        }

        $this->refreshRunStats($run);
    }

    private function debugReport(string $hypothesisId, string $event, array $data): void
    {
        $url = $this->debugServerUrl();
        if (! $url) {
            return;
        }

        try {
            Http::timeout(0.5)->post($url, [
                'sessionId' => 'auto-dialer-outbound-calls',
                'runId' => 'pre-fix',
                'hypothesisId' => $hypothesisId,
                'location' => 'CampaignRunnerService',
                'msg' => '[DEBUG] '.$event,
                'data' => $data,
                'ts' => (int) floor(microtime(true) * 1000),
            ]);
        } catch (\Throwable) {
        }
    }

    private function debugServerUrl(): ?string
    {
        static $cached = null;
        static $loaded = false;

        if ($loaded) {
            return $cached;
        }

        $loaded = true;

        try {
            $paths = [
                base_path('.dbg/auto-dialer-outbound-calls.env'),
                dirname(base_path()) . DIRECTORY_SEPARATOR . '.dbg' . DIRECTORY_SEPARATOR . 'auto-dialer-outbound-calls.env',
            ];
            foreach ($paths as $path) {
                if (is_string($path) && is_file($path)) {
                    $contents = (string) file_get_contents($path);
                    foreach (preg_split("/\r\n|\n|\r/", $contents) ?: [] as $line) {
                        if (str_starts_with($line, 'DEBUG_SERVER_URL=')) {
                            $cached = trim(substr($line, strlen('DEBUG_SERVER_URL=')));
                            break 2;
                        }
                    }
                }
            }
        } catch (\Throwable) {
        }

        if (! is_string($cached) || $cached === '') {
            $cached = env('DEBUG_SERVER_URL') ?: null;
        }

        return is_string($cached) && $cached !== '' ? $cached : null;
    }

    private function refreshDialedQueueItems(CampaignRun $run, Campaign $campaign): void
    {
        $dialedItems = DialQueueItem::query()
            ->where('tenant_id', $campaign->tenant_id)
            ->where('campaign_run_id', $run->id)
            ->where('status', 'dialed')
            ->get();

        $staleCutoff = now()->subSeconds(self::STALE_LIVE_CALL_SECONDS);

        /** @var DialQueueItem $item */
        foreach ($dialedItems as $item) {
            if (! $item->last_call_session_id) {
                continue;
            }
            $call = CallSession::query()
                ->where('tenant_id', $campaign->tenant_id)
                ->where('id', $item->last_call_session_id)
                ->first();
            if (! $call) {
                continue;
            }

            if (
                in_array($call->status, ['queued', 'ringing', 'in_progress', 'connected'], true)
                && $call->updated_at
                && $call->updated_at->lt($staleCutoff)
            ) {
                $this->releaseAgentAssignment($item);

                $originalCallStatus = $call->status;
                $call->status = $originalCallStatus === 'ringing' ? 'no_answer' : 'failed';
                $call->failure_reason = $call->status === 'failed' ? 'stale_call_session' : null;
                $call->ended_at = now();
                $call->save();

                if ($item->attempt_count < $item->max_attempts) {
                    $item->status = 'pending';
                    $item->available_at = now()->addSeconds(30 * max(1, $item->attempt_count));
                    $item->failure_reason = 'stale_call_session_'.$originalCallStatus;
                    $item->save();
                    $run->retried_items = $run->retried_items + 1;
                } else {
                    $item->status = 'failed';
                    $item->failure_reason = 'stale_call_session_'.$originalCallStatus;
                    $item->processed_at = now();
                    $item->save();
                    $run->calls_failed = $run->calls_failed + 1;
                }

                continue;
            }

            if ($call->status === 'completed') {
                $item->status = 'completed';
                $item->processed_at = now();
                $item->failure_reason = null;
                $item->save();
                $this->releaseAgentAssignment($item);
                $run->calls_connected = $run->calls_connected + 1;
            } elseif (in_array($call->status, self::RETRYABLE_STATUSES, true)) {
                $this->releaseAgentAssignment($item);
                if ($item->attempt_count < $item->max_attempts) {
                    $item->status = 'pending';
                    $item->available_at = now()->addSeconds(30 * max(1, $item->attempt_count));
                    $item->failure_reason = $call->failure_reason ?: $call->status;
                    $item->save();
                    $run->retried_items = $run->retried_items + 1;
                } else {
                    $item->status = 'failed';
                    $item->failure_reason = $call->failure_reason ?: $call->status;
                    $item->processed_at = now();
                    $item->save();
                    $run->calls_failed = $run->calls_failed + 1;
                }
            }
        }

        $run->save();
        $this->refreshRunStats($run);
    }

    private function ensureCampaignAgentSessions(Campaign $campaign): void
    {
        $agentIds = CampaignAgentAssignment::query()
            ->where('tenant_id', $campaign->tenant_id)
            ->where('campaign_id', $campaign->id)
            ->pluck('agent_id')
            ->map(fn ($id) => (string) $id)
            ->filter()
            ->unique()
            ->values();

        if ($agentIds->isEmpty()) {
            return;
        }

        foreach ($agentIds as $agentId) {
            $session = AgentSession::query()->updateOrCreate(
                [
                    'tenant_id' => $campaign->tenant_id,
                    'agent_id' => $agentId,
                ],
                [
                    'status' => 'available',
                    'capacity' => 1,
                    'available_since' => now(),
                    'last_heartbeat_at' => now(),
                ]
            );

            $session->active_assignments = $this->syncAgentSessionActiveAssignments($campaign->tenant_id, (string) $session->id);
            if ($session->active_assignments < $session->capacity) {
                $session->status = 'available';
                $session->available_since = $session->available_since ?: now();
            }
            $session->last_heartbeat_at = now();
            $session->save();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildNoAvailableAgentsDebug(Campaign $campaign): array
    {
        $mappedAssignments = CampaignAgentAssignment::query()
            ->where('tenant_id', $campaign->tenant_id)
            ->where('campaign_id', $campaign->id)
            ->orderByDesc('updated_at')
            ->get(['agent_id', 'provider_phone_number_id', 'updated_at']);

        $mappedAgentIds = $mappedAssignments
            ->pluck('agent_id')
            ->map(fn ($id) => (string) $id)
            ->filter()
            ->unique()
            ->values();

        $mappingLastUpdatedAt = $mappedAssignments->first()?->updated_at?->toISOString();

        if ($mappedAgentIds->isEmpty()) {
            return [
                'mapped_agents_total' => 0,
                'eligible_agents' => 0,
                'online_agents' => 0,
                'offline_agents' => 0,
                'mapping_last_updated_at' => $mappingLastUpdatedAt,
                'agents' => [],
            ];
        }

        $maxAgents = 15;
        $sampleAgentIds = $mappedAgentIds->take($maxAgents)->values();

        $agents = Agent::query()
            ->where('tenant_id', $campaign->tenant_id)
            ->whereIn('id', $sampleAgentIds->all())
            ->get(['id', 'status', 'metadata'])
            ->keyBy(fn (Agent $agent) => (string) $agent->id);

        $sessions = AgentSession::query()
            ->where('tenant_id', $campaign->tenant_id)
            ->whereIn('agent_id', $sampleAgentIds->all())
            ->get(['id', 'agent_id', 'status', 'capacity', 'active_assignments', 'last_heartbeat_at'])
            ->keyBy(fn (AgentSession $session) => (string) $session->agent_id);

        $campaignNumberIds = $mappedAssignments
            ->whereNotNull('provider_phone_number_id')
            ->pluck('provider_phone_number_id')
            ->map(fn ($id) => (string) $id)
            ->filter()
            ->unique()
            ->values();

        $agentNumberIds = AgentPhoneAssignment::query()
            ->where('tenant_id', $campaign->tenant_id)
            ->whereIn('agent_id', $sampleAgentIds->all())
            ->where('status', 'active')
            ->pluck('provider_phone_number_id')
            ->map(fn ($id) => (string) $id)
            ->filter()
            ->unique()
            ->values();

        $allNumberIds = $campaignNumberIds->concat($agentNumberIds)->unique()->values();

        $numbers = $allNumberIds->isEmpty()
            ? collect()
            : ProviderPhoneNumber::query()
                ->where('tenant_id', $campaign->tenant_id)
                ->whereIn('id', $allNumberIds->all())
                ->get(['id', 'provider_account_id', 'status', 'is_validated', 'phone_number'])
                ->keyBy(fn (ProviderPhoneNumber $number) => (string) $number->id);

        $providerIds = $numbers->values()
            ->pluck('provider_account_id')
            ->map(fn ($id) => (string) $id)
            ->filter()
            ->unique()
            ->values();

        $providers = $providerIds->isEmpty()
            ? collect()
            : ProviderAccount::query()
                ->where('tenant_id', $campaign->tenant_id)
                ->whereIn('id', $providerIds->all())
                ->get(['id', 'status'])
                ->keyBy(fn (ProviderAccount $provider) => (string) $provider->id);

        $eligible = 0;
        $online = 0;
        $offline = 0;
        $rows = [];

        foreach ($sampleAgentIds as $agentId) {
            $agent = $agents->get($agentId);
            $session = $sessions->get($agentId);

            $agentStatus = (string) ($agent?->status ?? '');
            $sessionStatus = (string) ($session?->status ?? '');
            $sessionCapacity = (int) ($session?->capacity ?? 0);
            $sessionActiveAssignments = (int) ($session?->active_assignments ?? 0);
            $isOnline = in_array($sessionStatus, ['available', 'busy', 'on_break'], true);
            $isAvailable = $sessionStatus === 'available' && $sessionCapacity > 0 && $sessionActiveAssignments < $sessionCapacity;

            if ($isOnline) {
                $online++;
            } else {
                $offline++;
            }

            $destinationNumber = (string) (($agent?->metadata['destination_number'] ?? null) ?: '');

            $mappedNumberId = (string) ($mappedAssignments->firstWhere('agent_id', $agentId)?->provider_phone_number_id ?? '');
            $mappedNumber = $mappedNumberId !== '' ? $numbers->get($mappedNumberId) : null;
            $mappedProviderId = $mappedNumber?->provider_account_id ? (string) $mappedNumber->provider_account_id : '';
            $mappedProviderStatus = $mappedProviderId !== '' ? (string) ($providers->get($mappedProviderId)?->status ?? '') : '';
            $hasMappedDefaultNumber = (bool) (
                $mappedNumber
                && $mappedNumber->status === 'active'
                && (bool) $mappedNumber->is_validated
                && $mappedProviderStatus === 'active'
            );

            $fallbackNumberId = (string) (AgentPhoneAssignment::query()
                ->where('tenant_id', $campaign->tenant_id)
                ->where('agent_id', $agentId)
                ->where('status', 'active')
                ->value('provider_phone_number_id') ?? '');
            $fallbackNumber = $fallbackNumberId !== '' ? $numbers->get($fallbackNumberId) : null;
            $fallbackProviderId = $fallbackNumber?->provider_account_id ? (string) $fallbackNumber->provider_account_id : '';
            $fallbackProviderStatus = $fallbackProviderId !== '' ? (string) ($providers->get($fallbackProviderId)?->status ?? '') : '';
            $hasFallbackDefaultNumber = (bool) (
                $fallbackNumber
                && $fallbackNumber->status === 'active'
                && (bool) $fallbackNumber->is_validated
                && $fallbackProviderStatus === 'active'
            );

            $hasDefaultNumber = $hasMappedDefaultNumber || $hasFallbackDefaultNumber;

            $liveStatuses = ['queued', 'ringing', 'in_progress', 'connected'];
            $liveCalls = CallSession::query()
                ->where('tenant_id', $campaign->tenant_id)
                ->whereIn('status', $liveStatuses)
                ->where('metadata->agent_id', $agentId)
                ->orderByDesc('created_at')
                ->limit(3)
                ->get(['id', 'status', 'created_at']);
            $liveCallCount = $liveCalls->count();

            $isEligible = $agentStatus === 'active' && $isAvailable && $hasDefaultNumber;
            if ($isEligible) {
                $eligible++;
            }

            $rows[] = [
                'agent_id' => $agentId,
                'agent_active' => $agentStatus === 'active',
                'session_status' => $sessionStatus !== '' ? $sessionStatus : null,
                'online' => $isOnline,
                'available' => $isAvailable,
                'capacity' => $sessionCapacity,
                'active_assignments' => $sessionActiveAssignments,
                'last_heartbeat_at' => $session?->last_heartbeat_at?->toISOString(),
                'destination_number' => $destinationNumber !== '' ? $destinationNumber : null,
                'default_number' => [
                    'has_any' => $hasDefaultNumber,
                    'campaign_mapped' => $hasMappedDefaultNumber,
                    'fallback_assigned' => $hasFallbackDefaultNumber,
                ],
                'live_calls' => [
                    'count' => $liveCallCount,
                    'items' => $liveCalls
                        ->map(fn (CallSession $call) => [
                            'id' => (string) $call->id,
                            'status' => (string) $call->status,
                            'created_at' => $call->created_at?->toISOString(),
                        ])
                        ->values()
                        ->all(),
                ],
            ];
        }

        return [
            'mapped_agents_total' => $mappedAgentIds->count(),
            'eligible_agents' => $eligible,
            'online_agents' => $online,
            'offline_agents' => $offline,
            'mapping_last_updated_at' => $mappingLastUpdatedAt,
            'agents' => $rows,
        ];
    }

    private function releaseAgentAssignment(DialQueueItem $item): void
    {
        $assignment = AgentAssignment::query()
            ->where('tenant_id', $item->tenant_id)
            ->where('dial_queue_item_id', $item->id)
            ->whereNull('released_at')
            ->where('status', 'assigned')
            ->latest('assigned_at')
            ->first();

        if (! $assignment) {
            return;
        }

        $assignment->status = 'released';
        $assignment->released_at = now();
        $assignment->save();

        if (! $assignment->agent_session_id) {
            return;
        }

        $session = AgentSession::query()
            ->where('tenant_id', $item->tenant_id)
            ->where('id', $assignment->agent_session_id)
            ->first();

        if (! $session) {
            return;
        }

        $session->active_assignments = $this->syncAgentSessionActiveAssignments($item->tenant_id, (string) $session->id);
        if ($session->active_assignments < $session->capacity) {
            $session->status = 'available';
            $session->available_since = $session->available_since ?: now();
        }
        $session->last_heartbeat_at = now();
        $session->save();
    }

    private function syncAgentSessionActiveAssignments(string $tenantId, string $agentSessionId): int
    {
        $liveStatuses = ['queued', 'ringing', 'in_progress', 'connected'];
        $staleCutoff = now()->subSeconds(120);

        $staleAssignments = AgentAssignment::query()
            ->leftJoin('dial_queue_items', 'dial_queue_items.id', '=', 'agent_assignments.dial_queue_item_id')
            ->leftJoin('call_sessions', 'call_sessions.id', '=', 'dial_queue_items.last_call_session_id')
            ->where('agent_assignments.tenant_id', $tenantId)
            ->where('agent_assignments.agent_session_id', $agentSessionId)
            ->whereNull('agent_assignments.released_at')
            ->where('agent_assignments.status', 'assigned')
            ->where(function ($query) use ($liveStatuses) {
                $query->whereNull('call_sessions.id')
                    ->orWhereNotIn('call_sessions.status', $liveStatuses);
            })
            ->select(['agent_assignments.id'])
            ->limit(200)
            ->pluck('agent_assignments.id')
            ->map(fn ($id) => (string) $id)
            ->filter()
            ->values();

        $staleLiveAssignments = AgentAssignment::query()
            ->leftJoin('dial_queue_items', 'dial_queue_items.id', '=', 'agent_assignments.dial_queue_item_id')
            ->leftJoin('call_sessions', 'call_sessions.id', '=', 'dial_queue_items.last_call_session_id')
            ->where('agent_assignments.tenant_id', $tenantId)
            ->where('agent_assignments.agent_session_id', $agentSessionId)
            ->whereNull('agent_assignments.released_at')
            ->where('agent_assignments.status', 'assigned')
            ->whereIn('call_sessions.status', $liveStatuses)
            ->where('call_sessions.updated_at', '<', $staleCutoff)
            ->select(['agent_assignments.id'])
            ->limit(200)
            ->pluck('agent_assignments.id')
            ->map(fn ($id) => (string) $id)
            ->filter()
            ->values();

        $releaseIds = $staleAssignments->concat($staleLiveAssignments)->unique()->values();

        if ($releaseIds->isNotEmpty()) {
            AgentAssignment::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $releaseIds->all())
                ->update([
                    'status' => 'released',
                    'released_at' => now(),
                ]);
        }

        return (int) AgentAssignment::query()
            ->leftJoin('dial_queue_items', 'dial_queue_items.id', '=', 'agent_assignments.dial_queue_item_id')
            ->leftJoin('call_sessions', 'call_sessions.id', '=', 'dial_queue_items.last_call_session_id')
            ->where('agent_assignments.tenant_id', $tenantId)
            ->where('agent_assignments.agent_session_id', $agentSessionId)
            ->whereNull('agent_assignments.released_at')
            ->where('agent_assignments.status', 'assigned')
            ->whereIn('call_sessions.status', $liveStatuses)
            ->where('call_sessions.updated_at', '>=', $staleCutoff)
            ->count('agent_assignments.id');
    }

    private function refreshRunStats(CampaignRun $run): void
    {
        $stats = DialQueueItem::query()
            ->where('campaign_run_id', $run->id)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count")
            ->selectRaw("SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing_count")
            ->selectRaw("SUM(CASE WHEN status = 'dialed' THEN 1 ELSE 0 END) as dialed_count")
            ->selectRaw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count")
            ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count")
            ->first();

        $pending = (int) ($stats->pending_count ?? 0);
        $processing = (int) ($stats->processing_count ?? 0);
        $dialed = (int) ($stats->dialed_count ?? 0);
        $completed = (int) ($stats->completed_count ?? 0);
        $failed = (int) ($stats->failed_count ?? 0);

        $run->total_items = (int) ($stats->total ?? 0);
        $run->queued_items = $pending;
        $run->completed_items = $completed;
        $run->failed_items = $failed;
        $run->last_tick_at = now();
        $run->save();

        if ($pending === 0 && $processing === 0 && $dialed === 0 && $run->status === 'running') {
            try {
                Log::info('Campaign run completing.', [
                    'tenant_id' => (string) ($run->tenant_id ?? ''),
                    'campaign_id' => (string) $run->campaign_id,
                    'campaign_run_id' => (string) $run->id,
                    'pending_items' => $pending,
                    'processing_items' => $processing,
                    'dialed_items' => $dialed,
                    'completed_items' => $completed,
                    'failed_items' => $failed,
                ]);
            } catch (\Throwable) {
            }

            $run->status = 'completed';
            $run->stopped_at = now();
            $run->save();

            Campaign::query()->where('id', $run->campaign_id)->update(['status' => 'completed']);
            $this->notifyCampaignCompletion($run, $run->campaign ?: Campaign::query()->find($run->campaign_id));
        }
    }

    private function availableDispatchSlots(CampaignRun $run, Campaign $campaign): int
    {
        $windowStart = $run->pacing_window_started_at;
        if (! $windowStart || $windowStart->diffInSeconds(now()) >= 60) {
            $run->pacing_window_started_at = now();
            $run->calls_dispatched_in_window = 0;
            $run->save();
        }

        $limit = (int) max(1, $campaign->calls_per_minute ?: $run->calls_per_minute ?: 20);
        $remainingByPacing = max(0, $limit - (int) $run->calls_dispatched_in_window);
        $queueSizeLimit = (int) max(1, $campaign->queue_size);
        $activeDialed = DialQueueItem::query()
            ->where('campaign_run_id', $run->id)
            ->whereIn('status', ['processing', 'dialed'])
            ->count();
        $remainingByQueue = max(0, $queueSizeLimit - $activeDialed);

        return min($remainingByPacing, $remainingByQueue);
    }

    private function pauseCampaignRun(CampaignRun $run, Campaign $campaign, string $reason): void
    {
        $metadata = (array) ($run->metadata ?? []);
        $metadata['pause_reason'] = $reason;
        $metadata['paused_by_system_at'] = now()->toISOString();

        $run->status = 'paused';
        $run->paused_at = now();
        $run->metadata = $metadata;
        $run->last_tick_at = now();
        $run->save();

        $campaign->status = 'paused';
        $campaign->save();
    }

    public function isWithinAllowedCallingWindow(Campaign $campaign, ?Lead $lead = null): bool
    {
        // 1. Check Global Tenant Calling Window
        $tenantSetting = TenantSetting::query()
            ->where('tenant_id', $campaign->tenant_id)
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
                    $now = Carbon::now($timezone);
                } catch (\Throwable) {
                    $now = Carbon::now('UTC');
                }

                // Check days
                if ($days !== []) {
                    $currentDay = $now->format('D'); // Mon, Tue, etc.
                    if (!in_array($currentDay, $days, true)) {
                        return false;
                    }
                }

                // Check time
                if ($start !== '' && $end !== '') {
                    $currentTime = $now->format('H:i');
                    if ($currentTime < $start || $currentTime > $end) {
                        return false;
                    }
                }
            }
        }

        // 2. Check Campaign Specific Schedule Window
        if (!$campaign->isWithinScheduleWindow()) {
            return false;
        }

        // 3. Check Campaign Specific Allowed Calling Hours
        $settings = (array) ($campaign->settings ?? []);
        $window = (array) ($settings['allowed_calling_hours'] ?? []);
        if ($window === []) {
            return true;
        }

        $start = (string) ($window['start'] ?? '09:00');
        $end = (string) ($window['end'] ?? '20:00');
        $timezone = (string) ($window['timezone'] ?? '');
        if ($lead) {
            $leadTimezone = (string) (($lead->notes['timezone'] ?? null) ?: '');
            if ($leadTimezone !== '') {
                $timezone = $leadTimezone;
            }
        }
        if ($timezone === '') {
            $timezone = (string) ($tenantSetting?->timezone ?? 'UTC');
        }

        try {
            $now = Carbon::now($timezone);
        } catch (\Throwable) {
            $now = Carbon::now('UTC');
        }

        $current = $now->format('H:i');

        return $current >= $start && $current <= $end;
    }

    private function notifyCampaignCompletion(CampaignRun $run, ?Campaign $campaign): void
    {
        if (! $campaign) {
            return;
        }

        $recipients = Membership::query()
            ->with(['user', 'role'])
            ->where('tenant_id', $run->tenant_id)
            ->where('status', 'active')
            ->whereHas('role', fn ($query) => $query->whereIn('slug', ['admin', 'agency', 'company_owner', 'super_admin']))
            ->get()
            ->map(fn (Membership $membership) => $membership->user)
            ->filter()
            ->unique('id')
            ->values();

        foreach ($recipients as $recipient) {
            Notification::query()->create([
                'tenant_id' => $run->tenant_id,
                'user_id' => $recipient->id,
                'type' => 'campaign.completed',
                'title' => 'Campaign Completed',
                'message' => sprintf('Campaign "%s" has completed.', (string) $campaign->name),
                'metadata' => [
                    'campaign_id' => $campaign->id,
                    'campaign_run_id' => $run->id,
                    'completed_items' => (int) $run->completed_items,
                    'failed_items' => (int) $run->failed_items,
                ],
            ]);

            if (! empty($recipient->email)) {
                try {
                    Mail::raw(
                        sprintf(
                            "Campaign \"%s\" completed.\nCompleted items: %d\nFailed items: %d",
                            (string) $campaign->name,
                            (int) $run->completed_items,
                            (int) $run->failed_items
                        ),
                        fn ($message) => $message
                            ->to($recipient->email)
                            ->subject('Campaign Completed')
                    );
                } catch (\Throwable) {
                    // Email delivery should not block campaign lifecycle completion.
                }
            }
        }
    }

    /**
     * @return Collection<int, AgentSession>
     */
    private function availableAgentSessions(string $tenantId): Collection
    {
        return AgentSession::query()
            ->whereNotNull('agent_id')
            ->where('tenant_id', $tenantId)
            ->where('status', 'available')
            ->orderBy('available_since')
            ->get()
            ->filter(fn (AgentSession $session) => $session->active_assignments < $session->capacity)
            ->values();
    }

    private function writeCallCampaignLog(string $level, string $message, array $context): void
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
