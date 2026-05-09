<?php

namespace App\Services\Providers;

use App\Support\IntegrationMode;
use Illuminate\Support\Facades\Http;
use Throwable;

class VonageAdapter implements ProviderAdapterInterface
{
    public function __construct(private readonly IntegrationMode $integrationMode)
    {
    }

    public function testConnection(array $credentials): array
    {
        if ($this->integrationMode->isSandbox()) {
            return ['ok' => true, 'code' => null, 'message' => null, 'mode' => 'sandbox'];
        }

        $hasJwt = ! empty($credentials['jwt']);
        $hasAppId = ! empty($credentials['application_id']);
        $hasPrivateKey = ! empty($credentials['private_key']);
        $hasFrom = ! empty($credentials['from_number']);

        if (($hasJwt || ($hasAppId && $hasPrivateKey)) && $hasFrom) {
            return ['ok' => true, 'code' => null, 'message' => null];
        }

        return [
            'ok' => false,
            'code' => 'PROVIDER_CREDENTIALS_INVALID',
            'message' => 'Vonage credentials are incomplete. Provide application_id + private_key (or a precomputed jwt) and from_number.',
        ];
    }

    public function fetchIncomingPhoneNumbers(array $credentials): array
    {
        return [];
    }

    public function validateNumberOwnership(array $credentials, string $phoneNumber): array
    {
        return [
            'ok' => false,
            'code' => 'NUMBER_VALIDATION_UNSUPPORTED',
            'message' => 'Number validation is not implemented for this provider.',
        ];
    }

    public function verifyWebhookSignature(string $rawPayload, array $headers, array $payload, array $credentials): bool
    {
        $provided = (string) ($headers['x-vonage-signature'][0] ?? '');
        $secret = (string) ($credentials['api_secret'] ?? '');
        if ($provided === '' || $secret === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $rawPayload, $secret);

        return hash_equals($expected, $provided);
    }

    public function makeOutboundCall(array $credentials, string $to, string $from, string $twimlUrl, string $statusCallbackUrl): array
    {
        if ($from === '') {
            return ['ok' => false, 'code' => 'FROM_NUMBER_MISSING', 'message' => 'No outbound caller ID is configured.'];
        }

        if ($this->integrationMode->isSandbox()) {
            return ['ok' => true, 'provider_call_id' => 'vonage_'.bin2hex(random_bytes(12)), 'mode' => 'sandbox'];
        }

        $jwt = $this->createJwt($credentials);
        if ($jwt === null) {
            return [
                'ok' => false,
                'code' => 'PROVIDER_CREDENTIALS_INVALID',
                'message' => 'Vonage outbound calls require application_id + private_key (or a precomputed jwt).',
            ];
        }

        try {
            $response = Http::timeout(10)
                ->acceptJson()
                ->withToken($jwt)
                ->post('https://api.nexmo.com/v1/calls', [
                    'to' => [
                        [
                            'type' => 'phone',
                            'number' => $to,
                        ],
                    ],
                    'from' => [
                        'type' => 'phone',
                        'number' => $from,
                    ],
                    'answer_url' => [$twimlUrl],
                    'answer_method' => 'GET',
                    'event_url' => [$statusCallbackUrl],
                    'event_method' => 'POST',
                ]);

            if ($response->successful()) {
                return ['ok' => true, 'provider_call_id' => (string) ($response->json()['uuid'] ?? '')];
            }

            $message = (string) (($response->json()['error_title'] ?? null)
                ?: ($response->json()['title'] ?? null)
                ?: ($response->json()['detail'] ?? null)
                ?: 'Provider call failed.');

            return ['ok' => false, 'code' => 'PROVIDER_CALL_FAILED', 'message' => $message];
        } catch (Throwable $exception) {
            return ['ok' => false, 'code' => 'PROVIDER_CONNECTIVITY_FAILED', 'message' => $exception->getMessage()];
        }
    }

    public function normalizeWebhookEvent(array $payload): array
    {
        $status = strtolower((string) ($payload['status'] ?? 'unknown'));
        $eventType = match ($status) {
            'started', 'ringing' => 'call.ringing',
            'answered' => 'call.answered',
            'completed' => 'call.completed',
            'failed', 'rejected', 'busy', 'timeout' => 'call.failed',
            default => 'call.updated',
        };

        return [
            'provider_event_type' => (string) ($payload['type'] ?? 'vonage.status_callback'),
            'event_type' => $eventType,
            'status' => $status,
            'provider_call_id' => (string) ($payload['uuid'] ?? ''),
            'duration_seconds' => isset($payload['duration']) ? (int) $payload['duration'] : null,
            'occurred_at' => now(),
        ];
    }

    private function createJwt(array $credentials): ?string
    {
        if (! empty($credentials['jwt'])) {
            return (string) $credentials['jwt'];
        }

        $applicationId = (string) ($credentials['application_id'] ?? '');
        $privateKey = (string) ($credentials['private_key'] ?? '');
        if ($applicationId === '' || $privateKey === '') {
            return null;
        }

        $key = openssl_pkey_get_private($privateKey);
        if ($key === false) {
            return null;
        }

        $now = time();
        $header = ['typ' => 'JWT', 'alg' => 'RS256'];
        $payload = [
            'application_id' => $applicationId,
            'iat' => $now,
            'exp' => $now + 300,
            'jti' => bin2hex(random_bytes(8)),
        ];

        $segments = [
            $this->base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES)),
            $this->base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES)),
        ];
        $signingInput = implode('.', $segments);

        $signature = '';
        $ok = openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256);
        openssl_free_key($key);
        if (! $ok) {
            return null;
        }

        return $signingInput.'.'.$this->base64UrlEncode($signature);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
