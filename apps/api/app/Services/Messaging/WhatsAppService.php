<?php

namespace App\Services\Messaging;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WhatsAppService
{
    public function send(string $to, string|array $bodyOrPayload, ?string $statusCallbackUrl = null, ?array $providerCredentials = null): array
    {
        $credentials = $providerCredentials ?? [];
        $metaToken = (string) ($credentials['meta_access_token'] ?? '');
        $metaPhoneNumberId = (string) ($credentials['phone_number_id'] ?? '');
        
        // Only use Meta logic if it's explicitly a Meta provider account or has Meta credentials
        $isMeta = ($metaToken !== '' && $metaPhoneNumberId !== '');
        
        if ($isMeta) {
            $normalizedTo = preg_replace('/[^0-9]/', '', (string) $to) ?: '';
            if ($normalizedTo === '') {
                return [
                    'ok' => false,
                    'error' => 'Recipient number is invalid.',
                    'status_code' => 422,
                ];
            }

            $payload = [
                'messaging_product' => 'whatsapp',
                'to' => $normalizedTo,
            ];

            if (is_array($bodyOrPayload)) {
                $payload['type'] = 'template';
                $payload = array_merge($payload, $bodyOrPayload);
            } else {
                $payload['type'] = 'text';
                $payload['text'] = ['body' => $bodyOrPayload];
            }

            Log::info('Sending Meta WhatsApp message.', [
                'to' => $to,
                'payload' => $payload,
            ]);

            $response = Http::timeout(12)
                ->withToken($metaToken)
                ->acceptJson()
                ->post("https://graph.facebook.com/v20.0/{$metaPhoneNumberId}/messages", $payload);

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
                'status' => 'accepted',
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

        if (is_array($bodyOrPayload)) {
            return [
                'ok' => false,
                'error' => 'Twilio provider does not support Meta template payloads directly. Use a custom template or switch to Meta provider.',
                'status_code' => 422,
            ];
        }

        $response = Http::asForm()
            ->withBasicAuth($sid, $token)
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                'From' => Str::startsWith($from, 'whatsapp:') ? $from : 'whatsapp:'.$from,
                'To' => Str::startsWith($to, 'whatsapp:') ? $to : 'whatsapp:'.$to,
                'Body' => $bodyOrPayload,
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
