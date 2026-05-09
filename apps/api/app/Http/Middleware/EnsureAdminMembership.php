<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminMembership
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json([
                'error' => [
                    'code' => 'AUTH_UNAUTHENTICATED',
                    'message' => 'Authentication required.',
                ],
            ], 401);
        }

        if ($user->is_platform_admin) {
            return $next($request);
        }

        $membership = $request->attributes->get('membership');
        $role = (string) ($request->attributes->get('org_scope')['role'] ?? $membership?->role?->slug ?? '');
        $allowedRoles = ['super_admin', 'admin', 'company_owner', 'company_admin'];

        if (! in_array($role, $allowedRoles, true)) {
            return response()->json([
                'error' => [
                    'code' => 'AUTH_FORBIDDEN',
                    'message' => 'Admin membership is required for this module.',
                ],
            ], 403);
        }

        return $next($request);
    }
}
