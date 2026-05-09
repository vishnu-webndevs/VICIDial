<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\TenantPlan;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SubscriptionController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $subscription = Subscription::query()
            ->with('plan')
            ->where('tenant_id', $tenant->id)
            ->latest('created_at')
            ->first();

        return response()->json([
            'data' => $subscription,
        ]);
    }

    public function changePlan(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'plan_slug' => ['required', 'string', 'exists:plans,slug'],
            'billing_cycle' => ['nullable', 'in:monthly,yearly'],
        ]);

        $subscription = Subscription::query()
            ->where('tenant_id', $tenant->id)
            ->latest('created_at')
            ->firstOrFail();
        $targetPlan = Plan::query()->where('slug', $validated['plan_slug'])->where('is_active', true)->firstOrFail();

        DB::transaction(function () use ($subscription, $targetPlan, $validated, $request, $tenant) {
            $oldPlanId = $subscription->plan_id;
            $oldCycle = $subscription->billing_cycle;

            $subscription->plan_id = $targetPlan->id;
            $subscription->billing_cycle = $validated['billing_cycle'] ?? $subscription->billing_cycle;
            $subscription->status = 'active';

            $subscription->save();

            if ($this->hasTable('tenant_plans')) {
                TenantPlan::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('status', 'active')
                    ->update([
                        'status' => 'inactive',
                        'expires_at' => now(),
                    ]);

                TenantPlan::query()->create([
                    'tenant_id' => $tenant->id,
                    'plan_id' => $targetPlan->id,
                    'billing_cycle' => $subscription->billing_cycle,
                    'started_at' => now(),
                    'status' => 'active',
                ]);
            }

            foreach ($subscription->usageMeters as $meter) {
                if ($meter->meter_type === 'api_requests') {
                    $meter->limit_units = $targetPlan->api_quota_monthly;
                } elseif ($meter->meter_type === 'call_minutes') {
                    $meter->limit_units = $targetPlan->call_minutes_monthly;
                } elseif ($meter->meter_type === 'webhook_events') {
                    $meter->limit_units = $targetPlan->webhook_events_monthly;
                }
                $meter->save();
            }

            $this->auditLogger->log(
                action: 'billing.plan_changed',
                resourceType: 'subscription',
                resourceId: $subscription->id,
                tenantId: $tenant->id,
                actorId: $request->user()?->id,
                oldValues: ['plan_id' => $oldPlanId, 'billing_cycle' => $oldCycle],
                newValues: [
                    'plan_id' => $subscription->plan_id,
                    'billing_cycle' => $subscription->billing_cycle,
                ],
                request: $request
            );
        });

        $subscription->refresh()->load('plan');

        return response()->json([
            'data' => $subscription,
        ]);
    }

    private function hasTable(string $table): bool
    {
        try {
            return DB::getSchemaBuilder()->hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }
}
