<?php

namespace App\Http\Middleware;

use App\Models\Membership;
use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $tenantId = (string) $request->header('X-Tenant-Id', '');
        
        $memberships = Membership::query()
            ->with(['tenant', 'role.permissions'])
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->get();

        $request->attributes->set('tenant_ids', $memberships->pluck('tenant_id')->values()->all());

        $membership = null;
        if ($tenantId !== '') {
            $membership = $memberships->firstWhere('tenant_id', $tenantId);
        } elseif ($memberships->count() === 1) {
            $membership = $memberships->first();
        }

        // Platform admins can access any tenant by explicit header.
        if (! $membership && $tenantId !== '' && $user->is_platform_admin) {
            $tenant = Tenant::query()->find($tenantId);
            if ($tenant) {
                $request->attributes->set('tenant', $tenant);
                $request->attributes->set('membership', null);
                $request->attributes->set('org_scope', [
                    'role' => 'super_admin',
                    'agency_unit_id' => null,
                    'team_unit_id' => null,
                ]);

                // Dispatch asynchronous billing usage logging (wrapped in try-catch for robustness)
                try {
                    dispatch(new \App\Jobs\IncrementBillingUsage((string) $tenant->id));
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('Failed to dispatch IncrementBillingUsage: ' . $e->getMessage());
                }

                return $next($request);
            }
        }

        if ($membership) {
            // Check trial/subscription status for non-platform admin users
            if (! $user->is_platform_admin) {
                $isBillingRoute = $request->is('*auth/me') || $request->is('*subscription') || $request->is('*subscription/change-plan');
                
                if (! $isBillingRoute) {
                    $subscription = \App\Models\Subscription::query()
                        ->where('tenant_id', $membership->tenant_id)
                        ->latest()
                        ->first();

                    if ($subscription) {
                        if ($subscription->status === 'trialing' && $subscription->trial_ends_at && $subscription->trial_ends_at->isPast()) {
                        return response()->json([
                            'error' => [
                                'code' => 'TRIAL_EXPIRED',
                                'message' => 'Your 14-day free trial has expired. Please purchase a paid plan to continue using the platform.',
                            ],
                        ], 403);
                    }
                    if (in_array($subscription->status, ['canceled', 'unpaid', 'past_due'], true)) {
                        return response()->json([
                            'error' => [
                                'code' => 'SUBSCRIPTION_INACTIVE',
                                'message' => 'Your subscription is inactive. Please purchase a paid plan to access the platform.',
                            ],
                        ], 403);
                    }
                }
            }
        }

            $request->attributes->set('tenant', $membership->tenant);
            $request->attributes->set('membership', $membership);
            $request->attributes->set('org_scope', [
                'role' => (string) ($membership->role?->slug ?? ''),
                'agency_unit_id' => $membership->agency_unit_id,
                'team_unit_id' => $membership->team_unit_id,
            ]);

            // Dispatch asynchronous billing usage logging (wrapped in try-catch for robustness)
            try {
                if ($membership->tenant) {
                    dispatch(new \App\Jobs\IncrementBillingUsage((string) $membership->tenant->id));
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Failed to dispatch IncrementBillingUsage: ' . $e->getMessage());
            }

            return $next($request);
        }

        return response()->json([
            'error' => [
                'code' => 'TENANT_CONTEXT_REQUIRED',
                'message' => $tenantId === ''
                    ? 'X-Tenant-Id header is required.'
                    : 'You do not have access to the requested tenant.',
            ],
        ], 403);
    }
}
