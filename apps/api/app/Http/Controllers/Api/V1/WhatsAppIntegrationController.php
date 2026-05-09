<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ProviderAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class WhatsAppIntegrationController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $provider = ProviderAccount::query()
            ->where('tenant_id', $tenant->id)
            ->where('provider_type', 'meta_whatsapp')
            ->latest('created_at')
            ->first();

        return response()->json([
            'data' => [
                'provider' => $provider ? $this->serializeProvider($provider) : null,
            ],
        ]);
    }

    public function upsert(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'display_name' => ['nullable', 'string', 'max:120'],
            'meta_app_id' => ['nullable', 'string', 'max:120'],
            'meta_app_secret' => ['nullable', 'string', 'max:200'],
            'meta_access_token' => ['nullable', 'string', 'max:4000'],
            'whatsapp_business_account_id' => ['nullable', 'string', 'max:120'],
            'phone_number_id' => ['nullable', 'string', 'max:120'],
            'webhook_verify_token' => ['nullable', 'string', 'max:120'],
        ]);

        $provider = ProviderAccount::query()
            ->where('tenant_id', $tenant->id)
            ->where('provider_type', 'meta_whatsapp')
            ->latest('created_at')
            ->first();

        $provider = $provider ?: new ProviderAccount();
        $provider->tenant_id = $tenant->id;
        $provider->provider_type = 'meta_whatsapp';
        $provider->display_name = (string) ($validated['display_name'] ?? 'Meta WhatsApp');
        $provider->status = $validated['enabled'] ? 'active' : 'inactive';
        $provider->credentials_encrypted = array_filter([
            'meta_app_id' => $validated['meta_app_id'] ?? null,
            'meta_app_secret' => $validated['meta_app_secret'] ?? null,
            'meta_access_token' => $validated['meta_access_token'] ?? null,
            'whatsapp_business_account_id' => $validated['whatsapp_business_account_id'] ?? null,
            'phone_number_id' => $validated['phone_number_id'] ?? null,
            'webhook_verify_token' => $validated['webhook_verify_token'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
        $provider->save();

        return response()->json(['data' => ['provider' => $this->serializeProvider($provider)]], 200);
    }

    public function test(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $provider = ProviderAccount::query()
            ->where('tenant_id', $tenant->id)
            ->where('provider_type', 'meta_whatsapp')
            ->latest('created_at')
            ->first();

        if (! $provider) {
            return response()->json([
                'error' => [
                    'code' => 'META_PROVIDER_MISSING',
                    'message' => 'Meta WhatsApp integration is not configured yet.',
                ],
            ], 404);
        }

        $credentials = (array) ($provider->credentials_encrypted ?? []);
        $token = (string) ($credentials['meta_access_token'] ?? '');
        $phoneNumberId = (string) ($credentials['phone_number_id'] ?? '');

        if ($token === '' || $phoneNumberId === '') {
            return response()->json([
                'error' => [
                    'code' => 'META_CREDENTIALS_MISSING',
                    'message' => 'meta_access_token and phone_number_id are required.',
                ],
            ], 422);
        }

        $response = Http::timeout(10)
            ->withToken($token)
            ->acceptJson()
            ->get("https://graph.facebook.com/v25.0/{$phoneNumberId}", [
                'fields' => 'display_phone_number,verified_name',
            ]);

        $provider->last_tested_at = now();
        if ($response->successful()) {
            $provider->status = 'active';
            $provider->last_error_code = null;
            $provider->last_error_message = null;
            $provider->save();

            return response()->json([
                'data' => [
                    'ok' => true,
                    'provider' => $this->serializeProvider($provider),
                    'meta' => [
                        'display_phone_number' => $response->json('display_phone_number'),
                        'verified_name' => $response->json('verified_name'),
                    ],
                ],
            ]);
        }

        $provider->status = 'error';
        $provider->last_error_code = 'META_TEST_FAILED';
        $provider->last_error_message = (string) ($response->json('error.message') ?? 'Meta WhatsApp test failed.');
        $provider->save();

        return response()->json([
            'data' => [
                'ok' => false,
                'provider' => $this->serializeProvider($provider),
                'error' => $provider->last_error_message,
                'status_code' => $response->status(),
            ],
        ], 200);
    }

    private function serializeProvider(ProviderAccount $provider): array
    {
        $credentials = (array) ($provider->credentials_encrypted ?? []);
        return [
            'id' => $provider->id,
            'provider_type' => $provider->provider_type,
            'display_name' => $provider->display_name,
            'status' => $provider->status,
            'last_tested_at' => $provider->last_tested_at?->toISOString(),
            'last_error_code' => $provider->last_error_code,
            'last_error_message' => $provider->last_error_message,
            'settings' => [
                'enabled' => $provider->status === 'active',
                'meta_app_id' => $credentials['meta_app_id'] ?? null,
                'meta_app_secret' => $credentials['meta_app_secret'] ?? null,
                'meta_access_token' => $credentials['meta_access_token'] ?? null,
                'whatsapp_business_account_id' => $credentials['whatsapp_business_account_id'] ?? null,
                'phone_number_id' => $credentials['phone_number_id'] ?? null,
                'webhook_verify_token' => $credentials['webhook_verify_token'] ?? null,
            ],
        ];
    }
}

