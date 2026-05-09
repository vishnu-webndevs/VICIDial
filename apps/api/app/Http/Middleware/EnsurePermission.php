<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
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
        if (! $membership) {
            return response()->json([
                'error' => [
                    'code' => 'AUTH_FORBIDDEN',
                    'message' => 'Tenant context is required.',
                ],
            ], 403);
        }

        $requested = collect(preg_split('/[|,\s]+/', $permission) ?: [])
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->values();
        $permissionSet = $membership->role?->permissions?->pluck('slug') ?? collect();
        $allowed = $requested->isEmpty()
            ? false
            : $requested->contains(fn (string $slug) => $permissionSet->contains($slug));

        if (! $allowed) {
            return response()->json([
                'error' => [
                    'code' => 'AUTH_FORBIDDEN',
                    'message' => 'Missing permission: '.$requested->implode(' OR '),
                ],
            ], 403);
        }

        return $next($request);
    }
}
