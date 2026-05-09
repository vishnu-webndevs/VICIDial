<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class ApiTokenController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $prefix = $this->tenantTokenPrefix($tenant->id);
        $tokens = PersonalAccessToken::query()
            ->where('tokenable_type', $request->user()::class)
            ->where('tokenable_id', $request->user()->id)
            ->where('name', 'like', "{$prefix}%")
            ->latest('id')
            ->get()
            ->map(fn (PersonalAccessToken $token) => [
                'id' => $token->id,
                'name' => $this->tokenDisplayName($token->name),
                'abilities' => $token->abilities ?? [],
                'last_used_at' => $token->last_used_at?->toISOString(),
                'expires_at' => $token->expires_at?->toISOString(),
                'created_at' => $token->created_at?->toISOString(),
            ])->values();

        return response()->json(['data' => $tokens]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'abilities' => ['nullable', 'array'],
            'abilities.*' => ['string', 'max:100'],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $tokenName = $this->tenantTokenPrefix($tenant->id).$validated['name'];
        $abilities = $validated['abilities'] ?? ['*'];
        $expiresAt = isset($validated['expires_in_days'])
            ? now()->addDays((int) $validated['expires_in_days'])
            : null;

        $token = $request->user()->createToken($tokenName, $abilities, $expiresAt);

        $this->auditLogger->log(
            action: 'api_token.created',
            resourceType: 'api_token',
            resourceId: (string) $token->accessToken->id,
            tenantId: $tenant->id,
            actorId: $request->user()?->id,
            newValues: ['name' => $validated['name'], 'abilities' => $abilities],
            request: $request
        );

        return response()->json([
            'data' => [
                'id' => $token->accessToken->id,
                'name' => $validated['name'],
                'abilities' => $abilities,
                'expires_at' => $expiresAt?->toISOString(),
                'token' => $token->plainTextToken,
            ],
        ], 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $prefix = $this->tenantTokenPrefix($tenant->id);
        $token = PersonalAccessToken::query()
            ->where('id', $id)
            ->where('tokenable_type', $request->user()::class)
            ->where('tokenable_id', $request->user()->id)
            ->firstOrFail();

        if (! str_starts_with((string) $token->name, $prefix)) {
            return response()->json([
                'error' => [
                    'code' => 'API_TOKEN_NOT_FOUND',
                    'message' => 'Token not found for this tenant context.',
                ],
            ], 404);
        }

        $displayName = $this->tokenDisplayName($token->name);
        $token->delete();

        $this->auditLogger->log(
            action: 'api_token.revoked',
            resourceType: 'api_token',
            resourceId: (string) $id,
            tenantId: $tenant->id,
            actorId: $request->user()?->id,
            oldValues: ['name' => $displayName],
            request: $request
        );

        return response()->json([], 204);
    }

    private function tenantTokenPrefix(string $tenantId): string
    {
        return "tenant:{$tenantId}:";
    }

    private function tokenDisplayName(string $rawName): string
    {
        $parts = explode(':', $rawName, 3);

        return $parts[2] ?? $rawName;
    }
}
