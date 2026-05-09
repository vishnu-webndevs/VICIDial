<?php

namespace App\Services\Campaigns;

use App\Models\AgentAssignment;
use App\Models\AgentSession;
use App\Models\CallSession;
use App\Models\Campaign;
use App\Models\CampaignRun;
use App\Models\DialQueueItem;
use App\Models\Lead;
use App\Models\Membership;
use App\Models\Notification;
use App\Models\TenantSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class CampaignRunnerService
{
    private const RETRYABLE_STATUSES = ['failed', 'busy', 'no_answer', 'timeout', 'rejected', 'canceled'];

    public function __construct(
        private readonly OutboundDialerService $dialerService
    ) {
    }

    public function tick(CampaignRun $run): void
    {
        $run->loadMissing('campaign');
        $campaign = $run->campaign;
        if (! $campaign || $run->status !== 'running' || $campaign->status !== 'running') {
            return;
        }

        $this->refreshDialedQueueItems($run, $campaign);
        $run->refresh();
        $campaign->refresh();
        if ($run->status !== 'running' || $campaign->status !== 'running') {
            return;
        }

        if (! $this->isWithinAllowedCallingWindow($campaign)) {
            $this->pauseCampaignRun($run, $campaign, 'outside_allowed_calling_window');

            return;
        }

        $dispatchable = $this->availableDispatchSlots($run, $campaign);
        if ($dispatchable <= 0) {
            $run->last_tick_at = now();
            $run->save();

            return;
        }

        $agents = $this->availableAgentSessions($campaign->tenant_id);
        if ($agents->isEmpty()) {
            if ($campaign->auto_pause_when_no_agents) {
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
            $run->last_tick_at = now();
            $run->save();

            return;
        }

        $agentIndex = 0;
        $activeAgents = $agents->values();

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
                if (($dialResult['ok'] ?? false) !== true || ! isset($dialResult['call'])) {
                    if ($item->attempt_count <= $item->max_attempts) {
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

    private function refreshDialedQueueItems(CampaignRun $run, Campaign $campaign): void
    {
        $dialedItems = DialQueueItem::query()
            ->where('tenant_id', $campaign->tenant_id)
            ->where('campaign_run_id', $run->id)
            ->where('status', 'dialed')
            ->get();

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
            if ($call->status === 'completed') {
                $item->status = 'completed';
                $item->processed_at = now();
                $item->failure_reason = null;
                $item->save();
                $run->calls_connected = $run->calls_connected + 1;
            } elseif (in_array($call->status, self::RETRYABLE_STATUSES, true)) {
                if ($item->attempt_count <= $item->max_attempts) {
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

    private function refreshRunStats(CampaignRun $run): void
    {
        $stats = DialQueueItem::query()
            ->where('campaign_run_id', $run->id)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as queued")
            ->selectRaw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed")
            ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed")
            ->first();

        $run->total_items = (int) ($stats->total ?? 0);
        $run->queued_items = (int) ($stats->queued ?? 0);
        $run->completed_items = (int) ($stats->completed ?? 0);
        $run->failed_items = (int) ($stats->failed ?? 0);
        $run->last_tick_at = now();
        $run->save();

        if ($run->queued_items === 0 && $run->status === 'running') {
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

    private function isWithinAllowedCallingWindow(Campaign $campaign, ?Lead $lead = null): bool
    {
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
            $timezone = (string) TenantSetting::query()
                ->where('tenant_id', $campaign->tenant_id)
                ->value('timezone') ?: 'UTC';
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
            ->where(function ($query) {
                $query->whereNull('last_heartbeat_at')
                    ->orWhere('last_heartbeat_at', '>=', now()->subMinutes(2));
            })
            ->orderBy('available_since')
            ->get()
            ->filter(fn (AgentSession $session) => $session->active_assignments < $session->capacity)
            ->values();
    }
}
