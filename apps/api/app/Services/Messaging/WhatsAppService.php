<?php

namespace App\Services\Messaging;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class WhatsAppService
{
    public function send(string $to, string $body, ?string $statusCallbackUrl = null, ?array $providerCredentials = null): array
    {
        $credentials = $providerCredentials ?? [];
        $metaToken = (string) ($credentials['meta_access_token'] ?? '');
        $metaPhoneNumberId = (string) ($credentials['phone_number_id'] ?? '');
        if ($metaToken !== '' || $metaPhoneNumberId !== '') {
            if ($metaToken === '' || $metaPhoneNumberId === '') {
                return [
                    'ok' => false,
                    'error' => 'Meta WhatsApp credentials are missing. Set meta_access_token and phone_number_id in the selected provider.',
                    'status_code' => 422,
                ];
            }

            $normalizedTo = preg_replace('/[^0-9]/', '', (string) $to) ?: '';
            if ($normalizedTo === '') {
                return [
                    'ok' => false,
                    'error' => 'Recipient number is invalid.',
                    'status_code' => 422,
                ];
            }

            $response = Http::timeout(12)
                ->withToken($metaToken)
                ->acceptJson()
                ->post("https://graph.facebook.com/v25.0/{$metaPhoneNumberId}/messages", [
                    'messaging_product' => 'whatsapp',
                    'to' => $normalizedTo,
                    'type' => 'text',
                    'text' => ['body' => $body],
                ]);

            if (! $response->successful()) {
                return [
                    'ok' => false,
                    'error' => (string) ($response->json('error.message') ?? 'Failed to send WhatsApp message'),
                    'status_code' => $response->status(),
                ];
            }

            $messageId = (string) ($response->json('messages.0.id') ?? '');
            return [
                'ok' => true,
                'provider_message_id' => $messageId !== '' ? $messageId : 'wa_meta_'.Str::lower(Str::random(20)),
                'status' => 'queued',
            ];
        }

        $sid = (string) ($credentials['account_sid'] ?? config('services.twilio.sid', ''));
        $token = (string) ($credentials['auth_token'] ?? config('services.twilio.token', ''));
        $from = (string) ($credentials['whatsapp_from'] ?? $credentials['from_number'] ?? config('services.twilio.whatsapp_from', ''));

        if ($sid === '' || $token === '' || $from === '') {
            if ($providerCredentials !== null) {
                return [
                    'ok' => false,
                    'error' => 'WhatsApp credentials are missing. Set account_sid/auth_token and whatsapp_from in the selected provider.',
                    'status_code' => 422,
                ];
            }

            return [
                'ok' => true,
                'provider_message_id' => 'wa_mock_'.Str::lower(Str::random(20)),
                'status' => 'queued',
            ];
        }

        $response = Http::asForm()
            ->withBasicAuth($sid, $token)
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                'From' => Str::startsWith($from, 'whatsapp:') ? $from : 'whatsapp:'.$from,
                'To' => Str::startsWith($to, 'whatsapp:') ? $to : 'whatsapp:'.$to,
                'Body' => $body,
                'StatusCallback' => $statusCallbackUrl,
            ]);

        if (! $response->successful()) {
            return [
                'ok' => false,
                'error' => (string) ($response->json('message') ?? 'Failed to send WhatsApp message'),
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
