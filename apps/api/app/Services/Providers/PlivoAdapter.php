<?php

namespace App\Services\Providers;

use App\Support\IntegrationMode;
use Illuminate\Support\Facades\Http;
use Throwable;

class PlivoAdapter implements ProviderAdapterInterface
{
    public function __construct(private readonly IntegrationMode $integrationMode)
    {
    }

    public function testConnection(array $credentials): array
    {
        if (!$this->hasRequiredCredentials($credentials, requireFrom: false)) {
            return [
                'ok' => false,
                'code' => 'PROVIDER_CREDENTIALS_INVALID',
                'message' => 'Plivo credentials (auth_id and auth_token) are required.',
            ];
        }

        if ($this->integrationMode->isSandbox()) {
            return ['ok' => true, 'code' => null, 'message' => null, 'mode' => 'sandbox'];
        }

        try {
            $authId = (string) ($credentials['auth_id'] ?? $credentials['account_sid'] ?? '');
            $authToken = (string) ($credentials['auth_token'] ?? '');

            $response = Http::timeout(8)
                ->withBasicAuth($authId, $authToken)
                ->acceptJson()
                ->get("https://api.plivo.com/v1/Account/{$authId}/");

            if ($response->successful()) {
                return ['ok' => true, 'code' => null, 'message' => null];
            }

            return [
                'ok' => false,
                'code' => 'PROVIDER_AUTH_FAILED',
                'message' => $this->extractPlivoError($response->json()),
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'code' => 'PROVIDER_CONNECTIVITY_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function fetchIncomingPhoneNumbers(array $credentials): array
    {
        if (!$this->hasRequiredCredentials($credentials, requireFrom: false)) {
            return [];
        }

        if ($this->integrationMode->isSandbox()) {
            return [
                [
                    'sid' => 'PLIVO_PN_SANDBOX_01',
                    'phone_number' => (string) ($credentials['from_number'] ?? '+15550002222'),
                    'friendly_name' => 'Plivo Sandbox Primary Number',
                    'capabilities' => ['voice' => true, 'sms' => true, 'mms' => false],
                ],
            ];
        }

        try {
            $authId = (string) ($credentials['auth_id'] ?? $credentials['account_sid'] ?? '');
            $authToken = (string) ($credentials['auth_token'] ?? '');

            $response = Http::timeout(8)
                ->withBasicAuth($authId, $authToken)
                ->acceptJson()
                ->get("https://api.plivo.com/v1/Account/{$authId}/Number/?limit=100");

            if (!$response->successful()) {
                return [];
            }

            $objects = (array) ($response->json()['objects'] ?? []);

            return collect($objects)
                ->map(fn(array $item) => [
                    'sid' => (string) ($item['number'] ?? ''),
                    'phone_number' => str_starts_with((string) ($item['number'] ?? ''), '+') ? (string) $item['number'] : '+' . (string) $item['number'],
                    'friendly_name' => (string) ($item['alias'] ?? $item['number'] ?? ''),
                    'capabilities' => [
                        'voice' => (bool) ($item['voice_enabled'] ?? true),
                        'sms' => (bool) ($item['sms_enabled'] ?? true),
                        'mms' => (bool) ($item['mms_enabled'] ?? false),
                    ],
                ])
                ->filter(fn(array $item) => $item['phone_number'] !== '')
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    public function validateNumberOwnership(array $credentials, string $phoneNumber): array
    {
        if (!$this->hasRequiredCredentials($credentials, requireFrom: false)) {
            return ['ok' => false, 'code' => 'PROVIDER_CREDENTIALS_INVALID', 'message' => 'Plivo credentials are incomplete.'];
        }

        if ($this->integrationMode->isSandbox()) {
            return ['ok' => true, 'code' => null, 'message' => null];
        }

        try {
            $numbers = $this->fetchIncomingPhoneNumbers($credentials);
            $matched = collect($numbers)->first(fn(array $item) => (string) $item['phone_number'] === $phoneNumber);
            if ($matched) {
                return ['ok' => true, 'code' => null, 'message' => null, 'number' => $matched];
            }

            return [
                'ok' => false,
                'code' => 'NUMBER_NOT_OWNED',
                'message' => 'Phone number is not owned by this Plivo account.',
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'code' => 'NUMBER_VALIDATION_FAILED',
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function verifyWebhookSignature(string $rawPayload, array $headers, array $payload, array $credentials): bool
    {
        $provided = (string) ($headers['x-plivo-signature-v2'][0] ?? $headers['x-plivo-signature'][0] ?? '');
        $authToken = (string) ($credentials['auth_token'] ?? '');
        if ($provided === '' || $authToken === '') {
            return false;
        }

        $url = request()->fullUrl();
        $nonce = (string) ($headers['x-plivo-signature-ma-nonce'][0] ?? '');
        $dataToSign = $url . $nonce;

        $expected = base64_encode(hash_hmac('sha256', $dataToSign, $authToken, true));

        return hash_equals($expected, $provided);
    }

    public function makeOutboundCall(array $credentials, string $to, string $from, string $twimlUrl, string $statusCallbackUrl): array
    {
        if (!$this->hasRequiredCredentials($credentials, requireFrom: false)) {
            return ['ok' => false, 'code' => 'PROVIDER_CREDENTIALS_INVALID', 'message' => 'Plivo auth_id or auth_token is missing.'];
        }

        if ($from === '') {
            return ['ok' => false, 'code' => 'FROM_NUMBER_MISSING', 'message' => 'No outbound caller ID is configured. Set a From number in Plivo provider credentials.'];
        }

        if ($this->integrationMode->isSandbox()) {
            return ['ok' => true, 'provider_call_id' => 'PLIVO_' . strtolower(bin2hex(random_bytes(16))), 'mode' => 'sandbox'];
        }

        try {
            $authId = (string) ($credentials['auth_id'] ?? $credentials['account_sid'] ?? '');
            $authToken = (string) ($credentials['auth_token'] ?? '');

            $formattedFrom = str_starts_with($from, '+') ? $from : '+' . ltrim($from, '+');
            $formattedTo = str_starts_with($to, '+') ? $to : '+' . ltrim($to, '+');

            $body = [
                'from' => $formattedFrom,
                'to' => $formattedTo,
                'answer_url' => $twimlUrl,
                'answer_method' => 'POST',
                'hangup_url' => $statusCallbackUrl,
                'hangup_method' => 'POST',
            ];

            $response = Http::timeout(10)
                ->withBasicAuth($authId, $authToken)
                ->acceptJson()
                ->post("https://api.plivo.com/v1/Account/{$authId}/Call/", $body);

            if ($response->successful()) {
                $json = (array) $response->json();
                $callUuid = (string) ($json['request_uuid'] ?? $json['call_uuid'] ?? '');
                return ['ok' => true, 'provider_call_id' => $callUuid];
            }

            return ['ok' => false, 'code' => 'PROVIDER_CALL_FAILED', 'message' => $this->extractPlivoError($response->json())];
        } catch (Throwable $exception) {
            return ['ok' => false, 'code' => 'PROVIDER_CONNECTIVITY_FAILED', 'message' => $exception->getMessage()];
        }
    }

    public function normalizeWebhookEvent(array $payload): array
    {
        $status = strtolower((string) ($payload['CallStatus'] ?? $payload['Event'] ?? 'unknown'));
        $eventType = match ($status) {
            'queued' => 'call.initiated',
            'ringing' => 'call.ringing',
            'in-progress' => 'call.answered',
            'completed' => 'call.completed',
            'failed', 'busy', 'no-answer', 'canceled' => 'call.failed',
            default => 'call.updated',
        };

        $normalizedStatus = match ($status) {
            'in-progress' => 'in_progress',
            'no-answer' => 'no_answer',
            default => $status,
        };

        $duration = null;
        if (isset($payload['Duration'])) {
            $duration = (int) $payload['Duration'];
        } elseif (isset($payload['BillDuration'])) {
            $duration = (int) $payload['BillDuration'];
        }

        return [
            'provider_event_type' => (string) ($payload['Event'] ?? $payload['CallStatus'] ?? 'plivo.voice_webhook'),
            'event_type' => $eventType,
            'status' => $normalizedStatus,
            'provider_call_id' => (string) ($payload['CallUUID'] ?? $payload['RequestUUID'] ?? ''),
            'duration_seconds' => $duration,
            'occurred_at' => now(),
        ];
    }

    private function hasRequiredCredentials(array $credentials, bool $requireFrom = true): bool
    {
        $hasAuthId = !empty($credentials['auth_id']) || !empty($credentials['account_sid']);
        $hasAuthToken = !empty($credentials['auth_token']);
        $hasFrom = !empty($credentials['from_number']);

        return $requireFrom ? ($hasAuthId && $hasAuthToken && $hasFrom) : ($hasAuthId && $hasAuthToken);
    }

    private function extractPlivoError(mixed $payload): string
    {
        if (is_string($payload)) {
            return $payload;
        }

        if (is_array($payload)) {
            $err = $payload['error'] ?? $payload['message'] ?? $payload['description'] ?? null;
            if ($err !== null) {
                if (is_string($err)) {
                    return $err;
                }
                if (is_array($err)) {
                    return $this->extractPlivoError($err);
                }
            }

            $strings = [];
            array_walk_recursive($payload, function ($value) use (&$strings) {
                if (is_string($value) && trim($value) !== '') {
                    $strings[] = trim($value);
                }
            });

            if (!empty($strings)) {
                return implode(' | ', array_unique($strings));
            }

            return json_encode($payload) ?: 'Plivo API request failed.';
        }

        return 'Plivo API request failed.';
    }
}
