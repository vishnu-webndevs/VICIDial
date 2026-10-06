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

    public function exchangeEmbeddedSignupCode(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'code' => ['nullable', 'string'],
            'redirect_uri' => ['nullable', 'string'],
            'whatsapp_business_account_id' => ['nullable', 'string'],
            'phone_number_id' => ['nullable', 'string'],
            'meta_access_token' => ['nullable', 'string'],
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
        $provider->display_name = $provider->display_name ?: 'Meta WhatsApp (Embedded Coexistence)';
        $provider->status = 'active';
        if (! $provider->credentials_owner_user_id && $request->user()?->id) {
            $provider->credentials_owner_user_id = $request->user()->id;
        }

        $nextCredentials = $existingCredentials;
        if (! empty($validated['whatsapp_business_account_id'])) {
            $nextCredentials['whatsapp_business_account_id'] = trim($validated['whatsapp_business_account_id']);
        }
        if (! empty($validated['phone_number_id'])) {
            $nextCredentials['phone_number_id'] = trim($validated['phone_number_id']);
        }
        if (! empty($validated['meta_access_token'])) {
            $nextCredentials['meta_access_token'] = trim($validated['meta_access_token']);
        }

        $code = trim((string) ($validated['code'] ?? ''));
        $appId = (string) ($nextCredentials['meta_app_id'] ?? config('services.meta.app_id', ''));
        $appSecret = (string) ($nextCredentials['meta_app_secret'] ?? config('services.meta.app_secret', ''));

        // If authorization code exist, perform OAuth code exchange with Meta Graph API
        if ($code !== '') {
            if ($appId === '' || $appSecret === '') {
                return response()->json([
                    'error' => [
                        'code' => 'META_CREDENTIALS_MISSING',
                        'message' => 'Meta App Secret is required to exchange authorization code. Please enter Meta App Secret under Advanced Settings and click Save.',
                    ],
                ], 422);
            }

            $redirectUri = (string) ($validated['redirect_uri'] ?? $request->header('referer') ?? '');
            if ($redirectUri !== '') {
                $redirectUri = strtok(strtok($redirectUri, '#'), '?');
            }

            $oauthParams = [
                'client_id' => $appId,
                'client_secret' => $appSecret,
                'code' => $code,
            ];
            if ($redirectUri !== '') {
                $oauthParams['redirect_uri'] = $redirectUri;
            }

            try {
                $exchangeResponse = Http::timeout(15)
                    ->acceptJson()
                    ->get('https://graph.facebook.com/v20.0/oauth/access_token', $oauthParams);

                if ($exchangeResponse->successful() && $exchangeResponse->json('access_token')) {
                    $nextCredentials['meta_access_token'] = $exchangeResponse->json('access_token');
                } else {
                    $metaErr = (string) ($exchangeResponse->json('error.message') ?? 'OAuth code exchange failed with Meta.');
                    \Illuminate\Support\Facades\Log::warning('Meta token exchange error: ' . $metaErr);
                    return response()->json([
                        'error' => [
                            'code' => 'META_TOKEN_EXCHANGE_FAILED',
                            'message' => $metaErr,
                        ],
                    ], 422);
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Meta Embedded Signup token exchange exception: ' . $e->getMessage());
                return response()->json([
                    'error' => [
                        'code' => 'META_TOKEN_EXCHANGE_EXCEPTION',
                        'message' => $e->getMessage(),
                    ],
                ], 500);
            }
        }

        // Auto-discover WABA ID and Phone Number ID from Meta API if token is present
        $token = (string) ($nextCredentials['meta_access_token'] ?? '');
        if ($token !== '') {
            try {
                // Fetch debug_token metadata to extract WABA ID or Phone Number ID
                $debugRes = Http::timeout(10)->get('https://graph.facebook.com/v20.0/debug_token', [
                    'input_token' => $token,
                    'access_token' => $token,
                ]);

                if ($debugRes->successful()) {
                    $granularScopes = data_get($debugRes->json(), 'data.granular_scopes', []);
                    foreach ($granularScopes as $scopeItem) {
                        $targetIds = (array) ($scopeItem['target_ids'] ?? []);
                        foreach ($targetIds as $tid) {
                            $tidStr = (string) $tid;
                            if (empty($nextCredentials['phone_number_id']) || empty($nextCredentials['whatsapp_business_account_id'])) {
                                $inspectRes = Http::timeout(10)->withToken($token)->get("https://graph.facebook.com/v20.0/{$tidStr}");
                                if ($inspectRes->successful()) {
                                    if ($inspectRes->json('display_phone_number')) {
                                        $nextCredentials['phone_number_id'] = $tidStr;
                                    } elseif ($inspectRes->json('name') || $inspectRes->json('id')) {
                                        if (empty($nextCredentials['whatsapp_business_account_id'])) {
                                            $nextCredentials['whatsapp_business_account_id'] = $tidStr;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }

                $wabaId = (string) ($nextCredentials['whatsapp_business_account_id'] ?? '');

                // If WABA ID is missing, try fetching from /me/client_whatsapp_business_accounts or /me/whatsapp_business_accounts
                if ($wabaId === '') {
                    $wabaRes = Http::timeout(10)->withToken($token)->get('https://graph.facebook.com/v20.0/me/client_whatsapp_business_accounts');
                    if (! $wabaRes->successful() || empty($wabaRes->json('data.0.id'))) {
                        $wabaRes = Http::timeout(10)->withToken($token)->get('https://graph.facebook.com/v20.0/me/whatsapp_business_accounts');
                    }
                    if ($wabaRes->successful() && ! empty($wabaRes->json('data.0.id'))) {
                        $wabaId = (string) $wabaRes->json('data.0.id');
                        $nextCredentials['whatsapp_business_account_id'] = $wabaId;
                    }
                }

                // If Phone Number ID is missing, fetch registered phone numbers for the WABA ID
                if (empty($nextCredentials['phone_number_id'])) {
                    if ($wabaId !== '') {
                        $phoneRes = Http::timeout(10)->withToken($token)->get("https://graph.facebook.com/v20.0/{$wabaId}/phone_numbers");
                        if ($phoneRes->successful() && ! empty($phoneRes->json('data.0.id'))) {
                            $nextCredentials['phone_number_id'] = (string) $phoneRes->json('data.0.id');
                        }
                    }
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Meta WABA/Phone auto-discovery exception: ' . $e->getMessage());
            }
        }

        if (empty($nextCredentials['webhook_verify_token'])) {
            $nextCredentials['webhook_verify_token'] = \Illuminate\Support\Str::random(32);
        }

        $provider->credentials_encrypted = array_filter($nextCredentials, fn ($v) => $v !== null && trim((string) $v) !== '');
        $provider->last_tested_at = now();
        $provider->save();

        return response()->json([
            'data' => [
                'ok' => true,
                'provider' => $this->serializeProvider($provider),
                'message' => 'Meta WhatsApp Embedded Signup Coexistence connected successfully.',
            ],
        ]);
    }

    private function serializeProvider(ProviderAccount $provider): array
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
                'meta_app_secret' => $metaAppSecret !== '' ? $metaAppSecret : null,
                'meta_access_token' => $metaAccessToken !== '' ? $metaAccessToken : null,
                'whatsapp_business_account_id' => $credentials['whatsapp_business_account_id'] ?? null,
                'phone_number_id' => $credentials['phone_number_id'] ?? null,
                'webhook_verify_token' => $verifyToken !== '' ? $verifyToken : null,
            ],
        ];
    }
}
