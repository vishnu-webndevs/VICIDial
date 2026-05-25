<?php

namespace App\Services\Messaging;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SmsService
{
    public function send(string $to, string $body, ?string $statusCallbackUrl = null, ?array $providerCredentials = null): array
    {
        if (app(\App\Support\IntegrationMode::class)->isSandbox()) {
            return [
                'ok' => true,
                'provider_message_id' => 'sms_mock_'.Str::lower(Str::random(20)),
                'status' => 'queued',
            ];
        }

        $credentials = $providerCredentials ?? [];
        $sid = (string) ($credentials['account_sid'] ?? config('services.twilio.sid', ''));
        $token = (string) ($credentials['auth_token'] ?? config('services.twilio.token', ''));
        $from = (string) ($credentials['from_number'] ?? config('services.twilio.from', ''));

        if ($sid === '' || $token === '' || $from === '') {
            if ($providerCredentials !== null) {
                return [
                    'ok' => false,
                    'error' => 'SMS credentials are missing. Set account_sid/auth_token and from_number in the selected provider.',
                    'status_code' => 422,
                ];
            }

            return [
                'ok' => true,
                'provider_message_id' => 'sms_mock_'.Str::lower(Str::random(20)),
                'status' => 'queued',
            ];
        }

        $response = Http::asForm()
            ->withBasicAuth($sid, $token)
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                'From' => $from,
                'To' => $to,
                'Body' => $body,
                'StatusCallback' => $statusCallbackUrl,
            ]);

        if (! $response->successful()) {
            return [
                'ok' => false,
                'error' => (string) ($response->json('message') ?? 'Failed to send SMS'),
                'status_code' => $response->status(),
            ];
        }

        return [
            'ok' => true,
            'provider_message_id' => (string) $response->json('sid'),
            'status' => (string) ($response->json('status') ?? 'queued'),
        ];
    }
}
