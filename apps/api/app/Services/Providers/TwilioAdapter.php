<?php

namespace App\Services\Providers;

use App\Support\IntegrationMode;
use Illuminate\Support\Facades\Http;
use Throwable;

class TwilioAdapter implements ProviderAdapterInterface
{
    public function __construct(private readonly IntegrationMode $integrationMode)
    {
    }

    public function testConnection(array $credentials): array
    {
        if (!$this->hasRequiredCredentials($credentials, requireFrom: false)) {
            return ['ok' => false, 'code' => 'PROVIDER_CREDENTIALS_INVALID', 'message' => 'Twilio credentials are incomplete.'];
        }

        if ($this->integrationMode->isSandbox()) {
            return ['ok' => true, 'code' => null, 'message' => null, 'mode' => 'sandbox'];
        }

        try {
            $response = $this->twilioRequest($credentials, '/IncomingPhoneNumbers.json?PageSize=1');
            if ($response->successful()) {
                return ['ok' => true, 'code' => null, 'message' => null];
            }

            return [
                'ok' => false,
                'code' => 'PROVIDER_AUTH_FAILED',
                'message' => $this->extractTwilioError($response->json()),
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
                    'sid' => 'PN_SANDBOX_01',
                    'phone_number' => (string) ($credentials['from_number'] ?? '+15550001111'),
                    'friendly_name' => 'Sandbox Primary Number',
                    'capabilities' => ['voice' => true, 'sms' => true, 'mms' => false],
                ],
            ];
        }

        try {
            $response = $this->twilioRequest($credentials, '/IncomingPhoneNumbers.json?PageSize=100');
            if (!$response->successful()) {
                return [];
            }

            return collect((array) ($response->json()['incoming_phone_numbers'] ?? []))
                ->map(fn(array $item) => [
                    'sid' => (string) ($item['sid'] ?? ''),
                    'phone_number' => (string) ($item['phone_number'] ?? ''),
                    'friendly_name' => (string) ($item['friendly_name'] ?? ''),
                    'capabilities' => [
                        'voice' => (bool) ($item['capabilities']['voice'] ?? false),
                        'sms' => (bool) ($item['capabilities']['sms'] ?? false),
                        'mms' => (bool) ($item['capabilities']['mms'] ?? false),
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
            return ['ok' => false, 'code' => 'PROVIDER_CREDENTIALS_INVALID', 'message' => 'Twilio credentials are incomplete.'];
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
                'message' => 'Phone number is not owned by this Twilio account.',
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
        $provided = (string) ($headers['x-twilio-signature'][0] ?? '');
        $secret = (string) ($credentials['auth_token'] ?? '');
        if ($provided === '' || $secret === '') {
            return false;
        }

        // Try request()->fullUrl() first (includes query string which Twilio signs if present)
        $fullUrl = request()->fullUrl();
        if ($this->checkSignature($fullUrl, $payload, $secret, $provided)) {
            return true;
        }

        // Try request()->url() (without query string)
        $url = request()->url();
        if ($this->checkSignature($url, $payload, $secret, $provided)) {
            return true;
        }

        // Try config('app.url') based URL
        $fallbackUrl = rtrim((string) config('app.url'), '/') . '/api/webhooks/twilio';
        $queryString = request()->getQueryString();
        $fallbackUrlWithQuery = $fallbackUrl . ($queryString ? '?' . $queryString : '');

        if ($this->checkSignature($fallbackUrlWithQuery, $payload, $secret, $provided)) {
            return true;
        }
        if ($this->checkSignature($fallbackUrl, $payload, $secret, $provided)) {
            return true;
        }

        // Try forcing HTTPS scheme
        if (str_starts_with($fallbackUrl, 'http://')) {
            $httpsUrl = 'https://' . substr($fallbackUrl, 7);
            $httpsUrlWithQuery = $httpsUrl . ($queryString ? '?' . $queryString : '');
            if ($this->checkSignature($httpsUrlWithQuery, $payload, $secret, $provided)) {
                return true;
            }
            if ($this->checkSignature($httpsUrl, $payload, $secret, $provided)) {
                return true;
            }
        }

        // Try forcing HTTP scheme
        if (str_starts_with($fallbackUrl, 'https://')) {
            $httpUrl = 'http://' . substr($fallbackUrl, 8);
            $httpUrlWithQuery = $httpUrl . ($queryString ? '?' . $queryString : '');
            if ($this->checkSignature($httpUrlWithQuery, $payload, $secret, $provided)) {
                return true;
            }
            if ($this->checkSignature($httpUrl, $payload, $secret, $provided)) {
                return true;
            }
        }

        return false;
    }

    private function checkSignature(string $url, array $payload, string $secret, string $provided): bool
    {
        $signaturePayload = $url;
        $sortedPayload = $payload;
        ksort($sortedPayload);
        foreach ($sortedPayload as $key => $value) {
            if (is_array($value)) {
                continue;
            }
            $signaturePayload .= (string) $key . (string) $value;
        }
        $expected = base64_encode(hash_hmac('sha1', $signaturePayload, $secret, true));

        return hash_equals($expected, $provided);
    }

    public function makeOutboundCall(array $credentials, string $to, string $from, string $twimlUrl, string $statusCallbackUrl): array
    {
        // Only account_sid and auth_token are required here; $from is provided as a parameter.
        if (!$this->hasRequiredCredentials($credentials, requireFrom: false)) {
            return ['ok' => false, 'code' => 'PROVIDER_CREDENTIALS_INVALID', 'message' => 'Twilio account_sid or auth_token is missing.'];
        }

        if ($from === '') {
            return ['ok' => false, 'code' => 'FROM_NUMBER_MISSING', 'message' => 'No outbound caller ID is configured. Set a From number in provider credentials or assign a validated number to the agent.'];
        }

        if ($this->integrationMode->isSandbox()) {
            return ['ok' => true, 'provider_call_id' => 'CA' . strtolower(bin2hex(random_bytes(16))), 'mode' => 'sandbox'];
        }

        try {
            $accountSid = (string) $credentials['account_sid'];
            $useInlineTwiml = $this->shouldUseInlineTwiml($twimlUrl);
            $dialMode = $this->extractDialMode($twimlUrl);
            $isMissedCall = $dialMode === 'missed_call';
            $params = [
                'To' => $to,
                'From' => $from,
                $useInlineTwiml ? 'Twiml' : 'Url' => $useInlineTwiml ? ($isMissedCall ? $this->missedCallTwiml() : $this->defaultOutboundTwiml()) : $twimlUrl,
                'StatusCallback' => $statusCallbackUrl,
                'StatusCallbackMethod' => 'POST',
            ];
            if ($isMissedCall) {
                $params['Timeout'] = 12;
            }

            $queryString = http_build_query($params);
            $queryString .= '&StatusCallbackEvent=initiated&StatusCallbackEvent=ringing&StatusCallbackEvent=answered&StatusCallbackEvent=completed';

            $response = Http::timeout(10)
                ->withBasicAuth($accountSid, (string) $credentials['auth_token'])
                ->withBody($queryString, 'application/x-www-form-urlencoded')
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Calls.json");

            if ($response->successful()) {
                return ['ok' => true, 'provider_call_id' => (string) ($response->json()['sid'] ?? '')];
            }

            return ['ok' => false, 'code' => 'PROVIDER_CALL_FAILED', 'message' => $this->extractTwilioError($response->json())];
        } catch (Throwable $exception) {
            return ['ok' => false, 'code' => 'PROVIDER_CONNECTIVITY_FAILED', 'message' => $exception->getMessage()];
        }
    }

    public function normalizeWebhookEvent(array $payload): array
    {
        $status = strtolower((string) ($payload['CallStatus'] ?? 'unknown'));
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

        return [
            'provider_event_type' => (string) ($payload['EventType'] ?? 'twilio.status_callback'),
            'event_type' => $eventType,
            'status' => $normalizedStatus,
            'provider_call_id' => (string) ($payload['CallSid'] ?? ''),
            'duration_seconds' => isset($payload['CallDuration']) ? (int) $payload['CallDuration'] : null,
            'occurred_at' => now(),
        ];
    }

    private function hasRequiredCredentials(array $credentials, bool $requireFrom = true): bool
    {
        $hasSid = !empty($credentials['account_sid']);
        $hasToken = !empty($credentials['auth_token']);
        $hasFrom = !empty($credentials['from_number']);

        return $requireFrom ? ($hasSid && $hasToken && $hasFrom) : ($hasSid && $hasToken);
    }

    private function twilioRequest(array $credentials, string $path)
    {
        $accountSid = (string) $credentials['account_sid'];
        $authToken = (string) $credentials['auth_token'];

        return Http::timeout(8)
            ->withBasicAuth($accountSid, $authToken)
            ->acceptJson()
            ->get("https://api.twilio.com/2010-04-01/Accounts/{$accountSid}{$path}");
    }

    private function extractTwilioError(mixed $payload): string
    {
        if (is_array($payload) && !empty($payload['message'])) {
            return (string) $payload['message'];
        }

        return 'Twilio authentication failed.';
    }

    private function shouldUseInlineTwiml(string $twimlUrl): bool
    {
        $host = (string) (parse_url($twimlUrl, PHP_URL_HOST) ?? '');
        if ($host === '') {
            return true;
        }

        return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }

    private function defaultOutboundTwiml(): string
    {
        return implode('', [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<Response>',
            '<Say voice="Polly.Joanna">Please hold while we connect your call.</Say>',
            '<Pause length="60"/>',
            '</Response>',
        ]);
    }

    private function missedCallTwiml(): string
    {
        return implode('', [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<Response>',
            '<Pause length="2"/>',
            '<Hangup/>',
            '</Response>',
        ]);
    }

    private function extractDialMode(string $twimlUrl): string
    {
        $query = (string) (parse_url($twimlUrl, PHP_URL_QUERY) ?? '');
        if ($query === '') {
            return '';
        }

        $params = [];
        parse_str($query, $params);
        $mode = $params['dial_mode'] ?? '';
        if (!is_string($mode)) {
            return '';
        }

        return strtolower(trim($mode));
    }
}
