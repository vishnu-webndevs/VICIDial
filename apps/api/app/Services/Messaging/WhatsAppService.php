<?php

namespace App\Services\Messaging;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WhatsAppService
{
    public function send(string $to, string|array $bodyOrPayload, ?string $statusCallbackUrl = null, ?array $providerCredentials = null, ?string $mediaUrl = null): array
    {
        if (app(\App\Support\IntegrationMode::class)->isSandbox()) {
            return [
                'ok' => true,
                'provider_message_id' => 'wa_mock_'.Str::lower(Str::random(20)),
                'status' => 'queued',
            ];
        }

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
            } elseif ($mediaUrl) {
                $ext = strtolower(pathinfo(parse_url($mediaUrl, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
                $textCaption = is_string($bodyOrPayload) && trim($bodyOrPayload) !== '' ? trim($bodyOrPayload) : null;

                if (in_array($ext, ['ogg', 'opus', 'mp3', 'wav', 'm4a', 'aac', 'amr'], true)) {
                    $payload['type'] = 'audio';
                    $payload['audio'] = ['link' => $mediaUrl];
                } elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                    $payload['type'] = 'image';
                    $payload['image'] = array_filter(['link' => $mediaUrl, 'caption' => $textCaption]);
                } elseif (in_array($ext, ['mp4', '3gp', 'mov'], true)) {
                    $payload['type'] = 'video';
                    $payload['video'] = array_filter(['link' => $mediaUrl, 'caption' => $textCaption]);
                } else {
                    $payload['type'] = 'document';
                    $payload['document'] = array_filter(['link' => $mediaUrl, 'caption' => $textCaption, 'filename' => basename($mediaUrl)]);
                }
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

        $twilioParams = [
            'From' => Str::startsWith($from, 'whatsapp:') ? $from : 'whatsapp:'.$from,
            'To' => Str::startsWith($to, 'whatsapp:') ? $to : 'whatsapp:'.$to,
            'Body' => $bodyOrPayload,
            'StatusCallback' => $statusCallbackUrl,
        ];
        if ($mediaUrl) {
            $twilioParams['MediaUrl'] = $mediaUrl;
        }

        $response = Http::asForm()
            ->withBasicAuth($sid, $token)
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", $twilioParams);

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
