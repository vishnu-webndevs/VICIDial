<?php

namespace App\Services;

use App\Models\Membership;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\Lead;
use Illuminate\Support\Facades\Log;

class LeadDistributionService
{
    /**
     * Determine if round-robin lead distribution is enabled for a tenant.
     */
    public function isRoundRobinEnabled(string $tenantId): bool
    {
        $setting = TenantSetting::query()->where('tenant_id', $tenantId)->first();
        $metadata = (array) ($setting?->metadata ?? []);
        $mode = (string) ($metadata['lead_distribution_mode'] ?? 'unassigned');

        return $mode === 'round_robin';
    }

    /**
     * Assign next agent using Round Robin if enabled and lead is currently unassigned.
     */
    public function assignNextAgentIfRoundRobin(Lead $lead): bool
    {
        if (! $this->isRoundRobinEnabled($lead->tenant_id)) {
            return false;
        }

        // Keep existing assignment if lead already has a specific agent assigned
        if ($lead->owner_agent_id || ($lead->owner_agent && $lead->owner_agent !== 'Unassigned')) {
            return false;
        }

        $nextAgent = $this->getNextRoundRobinUser($lead->tenant_id);
        if (! $nextAgent) {
            return false;
        }

        $agentName = trim(($nextAgent->first_name ?? '') . ' ' . ($nextAgent->last_name ?? ''));
        if ($agentName === '') {
            $agentName = $nextAgent->email;
        }

        $lead->owner_agent_id = $nextAgent->id;
        $lead->owner_agent = $agentName;
        $lead->save();

        return true;
    }

    /**
     * Get the next eligible user for Round Robin assignment in circular order.
     */
    public function getNextRoundRobinUser(string $tenantId): ?User
    {
        $memberships = Membership::query()
            ->with(['user', 'role'])
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->get();

        if ($memberships->isEmpty()) {
            return null;
        }

        // Filter active team members (exclude platform admins)
        $eligibleUsers = $memberships
            ->pluck('user')
            ->filter(fn ($u) => $u !== null && ! $u->is_platform_admin)
            ->values();

        if ($eligibleUsers->isEmpty()) {
            return null;
        }

        $setting = TenantSetting::query()->where('tenant_id', $tenantId)->firstOrCreate(
            ['tenant_id' => $tenantId],
            ['metadata' => []]
        );

        $metadata = (array) ($setting->metadata ?? []);
        $lastUserId = (string) ($metadata['last_round_robin_user_id'] ?? '');

        $currentIndex = -1;
        if ($lastUserId !== '') {
            foreach ($eligibleUsers as $idx => $usr) {
                if ($usr->id === $lastUserId) {
                    $currentIndex = $idx;
                    break;
                }
            }
        }

        $nextIndex = ($currentIndex + 1) % $eligibleUsers->count();
        $nextUser = $eligibleUsers[$nextIndex];

        $metadata['last_round_robin_user_id'] = $nextUser->id;
        $setting->metadata = $metadata;
        $setting->save();

        return $nextUser;
    }
}
