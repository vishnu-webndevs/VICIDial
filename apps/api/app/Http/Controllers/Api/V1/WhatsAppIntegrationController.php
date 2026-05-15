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

        $userId = (string) ($request->user()?->id ?? '');
        $includeSecrets = false;
        if ($provider && $userId !== '' && (string) ($provider->credentials_owner_user_id ?? '') === $userId) {
            $includeSecrets = true;
        }

        return response()->json([
            'data' => [
                'provider' => $provider ? $this->serializeProvider($provider, $includeSecrets) : null,
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
        $existingCredentials = (array) ($provider->credentials_encrypted ?? []);
        $provider->tenant_id = $tenant->id;
        $provider->provider_type = 'meta_whatsapp';
        $provider->display_name = (string) ($validated['display_name'] ?? 'Meta WhatsApp');
        $provider->status = $validated['enabled'] ? 'active' : 'inactive';
        if (! $provider->credentials_owner_user_id && $request->user()?->id) {
            $provider->credentials_owner_user_id = $request->user()->id;
        }

        $incomingKeys = [
            'meta_app_id',
            'meta_app_secret',
            'meta_access_token',
            'whatsapp_business_account_id',
            'phone_number_id',
            'webhook_verify_token',
        ];

        $nextCredentials = $existingCredentials;
        foreach ($incomingKeys as $key) {
            if (! array_key_exists($key, $validated)) {
                continue;
            }
            $value = trim((string) ($validated[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            $nextCredentials[$key] = $value;
        }

        $provider->credentials_encrypted = array_filter($nextCredentials, fn ($v) => $v !== null && trim((string) $v) !== '');
        $provider->save();

        $userId = (string) ($request->user()?->id ?? '');
        $includeSecrets = $userId !== '' && (string) ($provider->credentials_owner_user_id ?? '') === $userId;
        return response()->json(['data' => ['provider' => $this->serializeProvider($provider, $includeSecrets)]], 200);
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

    private function serializeProvider(ProviderAccount $provider, bool $includeSecrets = false): array
    {
        $credentials = (array) ($provider->credentials_encrypted ?? []);
        $metaAppSecret = trim((string) ($credentials['meta_app_secret'] ?? ''));
        $metaAccessToken = trim((string) ($credentials['meta_access_token'] ?? ''));
        $verifyToken = trim((string) ($credentials['webhook_verify_token'] ?? ''));

        $hasMetaAppSecret = $metaAppSecret !== '';
        $hasMetaAccessToken = $metaAccessToken !== '';
        $hasVerifyToken = $verifyToken !== '';

        $suffix = static function (string $value, int $length = 4): ?string {
            $value = trim($value);
            if ($value === '') {
                return null;
            }
            $length = max(1, $length);
            return mb_substr($value, -$length);
        };

        return [
            'id' => $provider->id,
            'provider_type' => $provider->provider_type,
            'display_name' => $provider->display_name,
            'status' => $provider->status,
            'last_tested_at' => $provider->last_tested_at?->toISOString(),
            'last_error_code' => $provider->last_error_code,
            'last_error_message' => $provider->last_error_message,
            'secrets' => [
                'meta_app_secret_configured' => $hasMetaAppSecret,
                'meta_access_token_configured' => $hasMetaAccessToken,
                'webhook_verify_token_configured' => $hasVerifyToken,
                'meta_app_secret_suffix' => $hasMetaAppSecret ? $suffix($metaAppSecret, 4) : null,
                'meta_access_token_suffix' => $hasMetaAccessToken ? $suffix($metaAccessToken, 6) : null,
                'webhook_verify_token_suffix' => $hasVerifyToken ? $suffix($verifyToken, 4) : null,
            ],
            'settings' => [
                'enabled' => $provider->status === 'active',
                'meta_app_id' => $credentials['meta_app_id'] ?? null,
                'meta_app_secret' => $includeSecrets ? ($metaAppSecret !== '' ? $metaAppSecret : null) : null,
                'meta_access_token' => $includeSecrets ? ($metaAccessToken !== '' ? $metaAccessToken : null) : null,
                'whatsapp_business_account_id' => $credentials['whatsapp_business_account_id'] ?? null,
                'phone_number_id' => $credentials['phone_number_id'] ?? null,
                'webhook_verify_token' => $includeSecrets ? ($verifyToken !== '' ? $verifyToken : null) : null,
            ],
        ];
    }
}
