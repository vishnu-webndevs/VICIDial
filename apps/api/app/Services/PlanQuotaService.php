<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\PlanUsage;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PlanQuotaService
{
    public function resolveActivePlan(Tenant $tenant): ?Plan
    {
        if (SchemaInspector::hasTable('tenant_plans')) {
            try {
                $tenantPlan = $tenant->activePlan()->with('plan.features')->first();
                if ($tenantPlan?->plan) {
                    return $tenantPlan->plan;
                }
            } catch (\Throwable) {
                // Fall through to legacy subscription-backed plan resolution.
            }
        }

        $subscriptionRelation = $tenant->activeSubscription();
        if (SchemaInspector::hasTable('plan_features')) {
            $subscriptionRelation->with('plan.features');
        } else {
            $subscriptionRelation->with('plan');
        }
        $subscriptionPlan = $subscriptionRelation->first()?->plan;
        if ($subscriptionPlan) {
            return $subscriptionPlan;
        }

        $query = Plan::query()
            ->where('slug', 'starter')
            ->where('is_active', true);
        if (SchemaInspector::hasTable('plan_features')) {
            $query->with('features');
        }

        return $query->first();
    }

    public function featureForRequest(string $method, string $routeUri): ?string
    {
        $normalizedPath = trim($routeUri, '/');
        $normalizedPath = (string) preg_replace('/^api\/v\d+\//i', '', $normalizedPath);
        $normalizedPath = (string) preg_replace('/^v\d+\//i', '', $normalizedPath);
        $normalized = strtoupper($method).' '.$normalizedPath;
        $map = (array) config('plan_limits.route_feature_map', []);

        foreach ($map as $pattern => $featureKey) {
            $regex = '/^'.str_replace('\*', '.*', preg_quote((string) $pattern, '/')).'$/i';
            if (preg_match($regex, $normalized) === 1) {
                return (string) $featureKey;
            }
        }

        return null;
    }

    public function syncFeatureUsage(string $tenantId, string $featureKey): int
    {
        $source = (array) data_get(config('plan_limits.usage_sources', []), $featureKey, []);
        if ($source === []) {
            return (int) PlanUsage::query()
                ->where('tenant_id', $tenantId)
                ->where('feature_key', $featureKey)
                ->value('current_value');
        }

        $table = (string) ($source['table'] ?? '');
        $tenantColumn = (string) ($source['tenant_column'] ?? 'tenant_id');
        if ($table === '') {
            return 0;
        }
        if (! SchemaInspector::hasColumn($table, $tenantColumn)) {
            return (int) PlanUsage::query()
                ->where('tenant_id', $tenantId)
                ->where('feature_key', $featureKey)
                ->value('current_value');
        }

        $query = DB::table($table)->where($tenantColumn, $tenantId);

        $activeColumn = (string) ($source['active_column'] ?? '');
        if ($activeColumn !== '') {
            $activeValues = $source['active_values'] ?? null;
            if (is_array($activeValues) && $activeValues !== []) {
                $query->whereIn($activeColumn, $activeValues);
            } else {
                $query->where($activeColumn, (string) ($source['active_value'] ?? 'active'));
            }
        }

        if (SchemaInspector::hasColumn($table, 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        $count = (int) $query->count();

        PlanUsage::query()->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'feature_key' => $featureKey,
            ],
            [
                'current_value' => $count,
                'last_synced_at' => now(),
            ]
        );

        return $count;
    }

    public function usageSnapshot(string $tenantId, ?Plan $plan = null): array
    {
        $usage = [];
        $featureKeys = [];

        if ($plan) {
            $featureKeys = $plan->features->pluck('key')->values()->all();
        } else {
            $featureKeys = PlanUsage::query()
                ->where('tenant_id', $tenantId)
                ->pluck('feature_key')
                ->values()
                ->all();
        }

        foreach ($featureKeys as $featureKey) {
            if (! is_string($featureKey) || $featureKey === '') {
                continue;
            }
            $usage[$featureKey] = $this->syncFeatureUsage($tenantId, $featureKey);
        }

        return $usage;
    }
}

class SchemaInspector
{
    public static function hasTable(string $table): bool
    {
        static $cache = [];
        $key = Str::lower('table:'.$table);
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        try {
            $cache[$key] = \Illuminate\Support\Facades\DB::getSchemaBuilder()->hasTable($table);
        } catch (\Throwable) {
            $cache[$key] = false;
        }

        return $cache[$key];
    }

    public static function hasColumn(string $table, string $column): bool
    {
        static $cache = [];
        $key = Str::lower($table.'.'.$column);
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        try {
            $cache[$key] = \Illuminate\Support\Facades\DB::getSchemaBuilder()->hasColumn($table, $column);
        } catch (\Throwable) {
            $cache[$key] = false;
        }

        return $cache[$key];
    }
}
