<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ProviderAccount;
use App\Services\AuditLogger;
use App\Services\Providers\ProviderAdapterManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProviderController extends Controller
{
    public function __construct(
        private readonly ProviderAdapterManager $adapterManager,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $providers = ProviderAccount::query()
            ->where('tenant_id', $tenant->id)
            ->latest('created_at')
            ->get()
            ->map(fn (ProviderAccount $provider) => $this->serializeProvider($provider))
            ->values();

        return response()->json(['data' => $providers]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'provider_type' => ['required', 'in:twilio,vonage'],
            'display_name' => ['required', 'string', 'max:100'],
            'credentials' => ['required', 'array'],
        ]);

        $provider = ProviderAccount::query()->create([
            'tenant_id' => $tenant->id,
            'provider_type' => $validated['provider_type'],
            'display_name' => $validated['display_name'],
            'credentials_encrypted' => $validated['credentials'],
            'status' => 'pending',
        ]);

        $this->auditLogger->log(
            action: 'provider.created',
            resourceType: 'provider_account',
            resourceId: $provider->id,
            tenantId: $tenant->id,
            actorId: $request->user()?->id,
            newValues: ['provider_type' => $provider->provider_type, 'status' => $provider->status],
            request: $request
        );

        return response()->json(['data' => $this->serializeProvider($provider)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $provider = ProviderAccount::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        $validated = $request->validate([
            'display_name' => ['sometimes', 'string', 'max:100'],
            'credentials' => ['sometimes', 'array'],
        ]);

        $oldValues = ['display_name' => $provider->display_name, 'status' => $provider->status];
        if (array_key_exists('display_name', $validated)) {
            $provider->display_name = $validated['display_name'];
        }
        if (array_key_exists('credentials', $validated)) {
            $existingCredentials = (array) $provider->credentials_encrypted;
            $incomingCredentials = (array) $validated['credentials'];
            $mergedCredentials = $existingCredentials;

            foreach ($incomingCredentials as $key => $value) {
                if (! is_string($value)) {
                    continue;
                }
                $trimmed = trim($value);
                if ($trimmed === '') {
                    continue;
                }
                $mergedCredentials[$key] = $trimmed;
            }

            if ($mergedCredentials !== $existingCredentials) {
                $provider->credentials_encrypted = $mergedCredentials;
                $provider->status = 'pending';
                $provider->last_error_code = null;
                $provider->last_error_message = null;
            }
        }
        $provider->save();

        $this->auditLogger->log(
            action: 'provider.updated',
            resourceType: 'provider_account',
            resourceId: $provider->id,
            tenantId: $tenant->id,
            actorId: $request->user()?->id,
            oldValues: $oldValues,
            newValues: ['display_name' => $provider->display_name, 'status' => $provider->status],
            request: $request
        );

        return response()->json(['data' => $this->serializeProvider($provider)]);
    }

    public function testConnection(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $provider = ProviderAccount::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        $result = $this->adapterManager
            ->for($provider->provider_type)
            ->testConnection((array) $provider->credentials_encrypted);

        $provider->last_tested_at = now();
        if ($result['ok']) {
            $provider->status = 'active';
            $provider->last_error_code = null;
            $provider->last_error_message = null;
        } else {
            $provider->status = 'error';
            $provider->last_error_code = $result['code'];
            $provider->last_error_message = $result['message'];
        }
        $provider->save();

        $this->auditLogger->log(
            action: 'provider.connection_tested',
            resourceType: 'provider_account',
            resourceId: $provider->id,
            tenantId: $tenant->id,
            actorId: $request->user()?->id,
            newValues: ['status' => $provider->status, 'error_code' => $provider->last_error_code],
            request: $request
        );

        return response()->json([
            'data' => [
                ...$this->serializeProvider($provider),
                'test_result' => $result,
            ],
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $provider = ProviderAccount::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        $oldValues = [
            'id' => $provider->id,
            'display_name' => $provider->display_name,
            'provider_type' => $provider->provider_type,
            'status' => $provider->status,
        ];

        $provider->delete();

        $this->auditLogger->log(
            action: 'provider.deleted',
            resourceType: 'provider_account',
            resourceId: $id,
            tenantId: $tenant->id,
            actorId: $request->user()?->id,
            oldValues: $oldValues,
            request: $request
        );

        return response()->json([
            'data' => [
                'id' => $id,
                'deleted' => true,
            ],
        ]);
    }

    public function failoverPolicy(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $providers = ProviderAccount::query()
            ->where('tenant_id', $tenant->id)
            ->orderBy('failover_priority')
            ->orderBy('created_at')
            ->get()
            ->map(fn (ProviderAccount $provider) => [
                'id' => $provider->id,
                'display_name' => $provider->display_name,
                'provider_type' => $provider->provider_type,
                'status' => $provider->status,
                'failover_priority' => $provider->failover_priority ?? 100,
                'is_fallback' => (bool) $provider->is_fallback,
            ])
            ->values();

        return response()->json(['data' => $providers]);
    }

    public function updateFailoverPolicy(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'providers' => ['required', 'array', 'min:1'],
            'providers.*.id' => ['required', 'uuid'],
            'providers.*.failover_priority' => ['required', 'integer', 'min:1', 'max:999'],
            'providers.*.is_fallback' => ['required', 'boolean'],
        ]);

        $providerIds = collect($validated['providers'])->pluck('id')->all();
        $existing = ProviderAccount::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('id', $providerIds)
            ->get()
            ->keyBy('id');

        if (count($existing) !== count($providerIds)) {
            return response()->json([
                'error' => [
                    'code' => 'PROVIDER_NOT_FOUND',
                    'message' => 'One or more providers were not found in tenant scope.',
                ],
            ], 404);
        }

        foreach ($validated['providers'] as $entry) {
            /** @var ProviderAccount $provider */
            $provider = $existing->get($entry['id']);
            $provider->failover_priority = (int) $entry['failover_priority'];
            $provider->is_fallback = (bool) $entry['is_fallback'];
            $provider->save();
        }

        $this->auditLogger->log(
            action: 'provider.failover_policy_updated',
            resourceType: 'provider_account',
            resourceId: null,
            tenantId: $tenant->id,
            actorId: $request->user()?->id,
            newValues: ['providers' => $validated['providers']],
            request: $request
        );

        return $this->failoverPolicy($request);
    }

    private function serializeProvider(ProviderAccount $provider): array
    {
        return [
            'id' => $provider->id,
            'provider_type' => $provider->provider_type,
            'display_name' => $provider->display_name,
            'status' => $provider->status,
            'failover_priority' => $provider->failover_priority ?? 100,
            'is_fallback' => (bool) $provider->is_fallback,
            'last_tested_at' => $provider->last_tested_at?->toISOString(),
            'last_error_code' => $provider->last_error_code,
            'last_error_message' => $provider->last_error_message,
            'credential_fields' => array_keys((array) $provider->credentials_encrypted),
            'credentials_masked' => true,
            'created_at' => $provider->created_at?->toISOString(),
            'updated_at' => $provider->updated_at?->toISOString(),
        ];
    }
}
