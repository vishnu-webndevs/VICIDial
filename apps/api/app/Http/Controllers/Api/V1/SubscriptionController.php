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
        $planQuotaService = app(\App\Services\PlanQuotaService::class);
        $plan = $planQuotaService->resolveActivePlan($tenant);

        $activeTenantPlan = TenantPlan::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->latest('started_at')
            ->first();

        $startedAt = $activeTenantPlan?->started_at ?? $tenant->created_at;
        $expiresAt = $activeTenantPlan?->expires_at
            ? $activeTenantPlan->expires_at
            : ($startedAt ? $startedAt->copy()->addDays(30) : null);

        $isExpired = $planQuotaService->isSubscriptionExpired($tenant);
        $daysRemaining = $expiresAt ? max(0, (int) ceil(now()->diffInSeconds($expiresAt, false) / 86400)) : 0;

        return response()->json([
            'data' => [
                'id' => $activeTenantPlan?->id ?? $tenant->id,
                'status' => $isExpired ? 'expired' : ($activeTenantPlan?->status ?? 'active'),
                'billing_cycle' => $activeTenantPlan?->billing_cycle ?? 'monthly',
                'plan' => $plan ? [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'slug' => $plan->slug,
                    'description' => $plan->description,
                    'price_monthly' => $plan->price_monthly,
                    'price_yearly' => $plan->price_yearly,
                ] : null,
                'started_at' => $startedAt ? $startedAt->toIso8601String() : null,
                'expires_at' => $expiresAt ? $expiresAt->toIso8601String() : null,
                'is_expired' => $isExpired,
                'days_remaining' => $daysRemaining,
            ],
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

        $isInactive = $subscription->status === 'canceled' ||
                      $subscription->status === 'unpaid' ||
                      ($subscription->status === 'trialing' && $subscription->trial_ends_at && $subscription->trial_ends_at->isPast());

        $isPlanChanging = $subscription->plan_id !== $targetPlan->id;
        $isCycleChanging = isset($validated['billing_cycle']) && $subscription->billing_cycle !== $validated['billing_cycle'];

        if ($isPlanChanging || $isCycleChanging || $isInactive) {
            return response()->json([
                'message' => 'Online payment integration is currently under maintenance. Please contact support to upgrade or reactivate your plan.',
            ], 402);
        }

        DB::transaction(function () use ($subscription, $targetPlan, $validated, $request, $tenant) {
            $oldPlanId = $subscription->plan_id;
            $oldCycle = $subscription->billing_cycle;

            $isInactive = $subscription->status === 'canceled' ||
                          $subscription->status === 'unpaid' ||
                          ($subscription->status === 'trialing' && $subscription->trial_ends_at && $subscription->trial_ends_at->isPast());

            $subscription->plan_id = $targetPlan->id;
            $subscription->billing_cycle = $validated['billing_cycle'] ?? $subscription->billing_cycle;

            if ($isInactive && $oldPlanId === $targetPlan->id) {
                // Keep the inactive/expired status if they select the same plan
            } else {
                $subscription->status = 'active';
                $subscription->trial_ends_at = null;
            }

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
