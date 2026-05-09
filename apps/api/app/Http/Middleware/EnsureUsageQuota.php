<?php

namespace App\Http\Middleware;

use App\Services\PlanQuotaService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUsageQuota
{
    public function __construct(private readonly PlanQuotaService $planQuotaService)
    {
    }

    public function handle(Request $request, Closure $next, ...$unused): Response
    {
        $tenant = $request->attributes->get('tenant');
        if (! $tenant) {
            return $next($request);
        }

        $routeUri = (string) ($request->route()?->uri() ?? trim($request->path(), '/'));
        $featureKey = $this->planQuotaService->featureForRequest($request->method(), $routeUri);
        if (! $featureKey) {
            return $next($request);
        }

        $plan = $this->planQuotaService->resolveActivePlan($tenant);
        if (! $plan) {
            return $next($request);
        }

        $limit = $plan->getLimit($featureKey);
        if ($limit < 0) {
            return $next($request);
        }

        $current = $this->planQuotaService->syncFeatureUsage($tenant->id, $featureKey);
        if ($current >= $limit) {
            $feature = $plan->featureMap()->get($featureKey);
            $label = $feature?->label ?? $featureKey;

            return response()->json([
                'error' => 'usage_limit_reached',
                'feature_key' => $featureKey,
                'label' => $label,
                'limit' => $limit,
                'current' => $current,
                'plan' => $plan->name,
                'upgrade_hint' => "Upgrade your plan to add more {$label}.",
            ], 403);
        }

        return $next($request);
    }
}
