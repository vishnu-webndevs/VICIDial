<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\Tenant;
use App\Models\TenantPlan;
use App\Services\PlanQuotaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PlanManagementController extends Controller
{
    public function __construct(private readonly PlanQuotaService $planQuotaService)
    {
    }

    public function indexPlans(): JsonResponse
    {
        $plans = Plan::query()
            ->withCount('tenantPlans')
            ->with('features')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $plans]);
    }

    public function storePlan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['required', 'string', 'max:50', 'unique:plans,slug'],
            'description' => ['nullable', 'string'],
            'price_monthly' => ['nullable', 'numeric', 'min:0'],
            'price_yearly' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'is_public' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $plan = Plan::query()->create(array_merge([
            'is_active' => true,
            'is_public' => false,
            'sort_order' => 0,
            'billing_cycle' => 'monthly',
        ], $validated));

        return response()->json(['data' => $plan->load('features')], 201);
    }

    public function showPlan(string $id): JsonResponse
    {
        $plan = Plan::query()->with('features')->findOrFail($id);

        return response()->json(['data' => $plan]);
    }

    public function updatePlan(Request $request, string $id): JsonResponse
    {
        $plan = Plan::query()->findOrFail($id);
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'slug' => ['sometimes', 'string', 'max:50', Rule::unique('plans', 'slug')->ignore($plan->id)],
            'description' => ['nullable', 'string'],
            'price_monthly' => ['nullable', 'numeric', 'min:0'],
            'price_yearly' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'is_public' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $plan->fill($validated)->save();

        return response()->json(['data' => $plan->fresh()->load('features')]);
    }

    public function deletePlan(string $id): JsonResponse
    {
        $plan = Plan::query()->findOrFail($id);
        $plan->delete();

        return response()->json([], 204);
    }

    public function reorderPlans(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan_ids' => ['required', 'array', 'min:1'],
            'plan_ids.*' => ['required', 'string', 'exists:plans,id'],
        ]);

        DB::transaction(function () use ($validated) {
            foreach ($validated['plan_ids'] as $index => $planId) {
                Plan::query()->where('id', $planId)->update(['sort_order' => $index + 1]);
            }
        });

        return response()->json(['data' => ['reordered' => true]]);
    }

    public function listFeatures(string $planId): JsonResponse
    {
        $features = PlanFeature::query()
            ->where('plan_id', $planId)
            ->orderBy('key')
            ->get();

        return response()->json(['data' => $features]);
    }

    public function storeFeature(Request $request, string $planId): JsonResponse
    {
        Plan::query()->findOrFail($planId);
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(['limit', 'boolean', 'text'])],
            'value' => ['required', 'string', 'max:120'],
            'label' => ['nullable', 'string', 'max:160'],
        ]);

        $feature = PlanFeature::query()->create(array_merge($validated, [
            'plan_id' => $planId,
        ]));

        return response()->json(['data' => $feature], 201);
    }

    public function updateFeature(Request $request, string $planId, string $featureId): JsonResponse
    {
        Plan::query()->findOrFail($planId);
        $feature = PlanFeature::query()->where('plan_id', $planId)->findOrFail($featureId);
        $validated = $request->validate([
            'key' => ['sometimes', 'string', 'max:120'],
            'type' => ['sometimes', Rule::in(['limit', 'boolean', 'text'])],
            'value' => ['sometimes', 'string', 'max:120'],
            'label' => ['nullable', 'string', 'max:160'],
        ]);

        $feature->fill($validated)->save();

        return response()->json(['data' => $feature->fresh()]);
    }

    public function deleteFeature(string $planId, string $featureId): JsonResponse
    {
        Plan::query()->findOrFail($planId);
        $feature = PlanFeature::query()->where('plan_id', $planId)->findOrFail($featureId);
        $feature->delete();

        return response()->json([], 204);
    }

    public function listCompanies(): JsonResponse
    {
        $companies = Tenant::query()
            ->with(['memberships'])
            ->orderBy('name')
            ->get()
            ->map(function (Tenant $tenant) {
                $plan = $this->planQuotaService->resolveActivePlan($tenant);
                $usage = $this->planQuotaService->usageSnapshot($tenant->id, $plan);

                return [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'status' => $tenant->status,
                    'plan' => $plan ? [
                        'id' => $plan->id,
                        'name' => $plan->name,
                        'slug' => $plan->slug,
                    ] : null,
                    'usage' => $usage,
                ];
            })
            ->values();

        return response()->json(['data' => $companies]);
    }

    public function companyPlan(string $companyId): JsonResponse
    {
        $tenant = Tenant::query()->findOrFail($companyId);
        $plan = $this->planQuotaService->resolveActivePlan($tenant);

        return response()->json([
            'data' => [
                'tenant_id' => $tenant->id,
                'plan' => $plan,
                'usage' => $this->planQuotaService->usageSnapshot($tenant->id, $plan),
            ],
        ]);
    }

    public function updateCompanyPlan(Request $request, string $companyId): JsonResponse
    {
        $tenant = Tenant::query()->findOrFail($companyId);
        $validated = $request->validate([
            'plan_id' => ['required', 'string', 'exists:plans,id'],
            'billing_cycle' => ['nullable', Rule::in(['monthly', 'yearly'])],
        ]);

        DB::transaction(function () use ($tenant, $validated) {
            TenantPlan::query()
                ->where('tenant_id', $tenant->id)
                ->where('status', 'active')
                ->update([
                    'status' => 'inactive',
                    'expires_at' => now(),
                ]);

            TenantPlan::query()->create([
                'tenant_id' => $tenant->id,
                'plan_id' => $validated['plan_id'],
                'billing_cycle' => (string) ($validated['billing_cycle'] ?? 'monthly'),
                'started_at' => now(),
                'status' => 'active',
            ]);
        });

        $plan = $this->planQuotaService->resolveActivePlan($tenant->fresh());

        return response()->json([
            'data' => [
                'tenant_id' => $tenant->id,
                'plan' => $plan,
            ],
        ]);
    }

    public function companyUsage(string $companyId): JsonResponse
    {
        $tenant = Tenant::query()->findOrFail($companyId);
        $plan = $this->planQuotaService->resolveActivePlan($tenant);

        return response()->json([
            'data' => [
                'tenant_id' => $tenant->id,
                'usage' => $this->planQuotaService->usageSnapshot($tenant->id, $plan),
            ],
        ]);
    }
}
