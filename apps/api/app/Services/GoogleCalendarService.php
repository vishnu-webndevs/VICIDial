<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleCalendarService
{
    /**
     * Create an event directly in Google Calendar using Service Account credentials.
     *
     * @param string|array $serviceAccountJson Service Account credentials JSON string or array
     * @param string $calendarId Target calendar ID (e.g. 'primary' or agent email address)
     * @param array $eventData Event details (title, description, start_iso, end_iso, timezone, attendee_emails)
     * @return array Response array ['success' => bool, 'event_id' => string|null, 'html_link' => string|null, 'error' => string|null]
     */
    public static function createEvent(mixed $serviceAccountJson, string $calendarId, array $eventData): array
    {
        try {
            $credentials = is_array($serviceAccountJson)
                ? $serviceAccountJson
                : json_decode((string) $serviceAccountJson, true);

            if (empty($credentials) || empty($credentials['client_email']) || empty($credentials['private_key'])) {
                return [
                    'success' => false,
                    'error' => 'Invalid or missing Google Service Account credentials.',
                ];
            }

            $clientEmail = $credentials['client_email'];
            $privateKey = $credentials['private_key'];

            // 1. Generate OAuth 2.0 JWT Assertion
            $now = time();
            $header = ['alg' => 'RS256', 'typ' => 'JWT'];
            $claimSet = [
                'iss' => $clientEmail,
                'scope' => 'https://www.googleapis.com/auth/calendar.events',
                'aud' => 'https://oauth2.googleapis.com/token',
                'exp' => $now + 3600,
                'iat' => $now,
            ];

            $base64Header = self::base64UrlEncode(json_encode($header));
            $base64Claims = self::base64UrlEncode(json_encode($claimSet));
            $toSign = "{$base64Header}.{$base64Claims}";

            $signature = '';
            if (!openssl_sign($toSign, $signature, $privateKey, 'SHA256')) {
                return [
                    'success' => false,
                    'error' => 'Failed to sign JWT with Service Account private key.',
                ];
            }

            $jwt = "{$toSign}." . self::base64UrlEncode($signature);

            // 2. Fetch Bearer Access Token
            $tokenResponse = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

            if (!$tokenResponse->successful()) {
                $errBody = $tokenResponse->body();
                Log::error("GoogleCalendarService: OAuth token request failed: " . $errBody);
                return [
                    'success' => false,
                    'error' => "OAuth token request failed: {$errBody}",
                ];
            }

            $accessToken = $tokenResponse->json('access_token');
            if (empty($accessToken)) {
                return [
                    'success' => false,
                    'error' => 'No access_token received from Google OAuth endpoint.',
                ];
            }

            // 3. Create Google Calendar Event
            $timeZone = $eventData['timezone'] ?? 'Asia/Kolkata';
            $attendees = [];
            if (!empty($eventData['attendee_emails']) && is_array($eventData['attendee_emails'])) {
                foreach ($eventData['attendee_emails'] as $email) {
                    $cleanEmail = trim((string) $email);
                    if (filter_var($cleanEmail, FILTER_VALIDATE_EMAIL)) {
                        $attendees[] = ['email' => $cleanEmail];
                    }
                }
            }

            $payload = [
                'summary' => $eventData['title'] ?? 'Site Visit / Meeting',
                'description' => $eventData['description'] ?? '',
                'start' => [
                    'dateTime' => $eventData['start_iso'],
                    'timeZone' => $timeZone,
                ],
                'end' => [
                    'dateTime' => $eventData['end_iso'],
                    'timeZone' => $timeZone,
                ],
            ];

            if (!empty($attendees)) {
                $payload['attendees'] = $attendees;
            }

            $encodedCalendarId = urlencode($calendarId);
            $eventResponse = Http::withHeaders([
                'Authorization' => "Bearer {$accessToken}",
                'Content-Type' => 'application/json',
            ])->post("https://www.googleapis.com/calendar/v3/calendars/{$encodedCalendarId}/events?sendUpdates=all", $payload);

            if (!$eventResponse->successful()) {
                $errBody = $eventResponse->body();
                Log::error("GoogleCalendarService: Event creation failed: " . $errBody);
                return [
                    'success' => false,
                    'error' => "Google Calendar API error: {$errBody}",
                ];
            }

            $resJson = $eventResponse->json();
            return [
                'success' => true,
                'event_id' => $resJson['id'] ?? null,
                'html_link' => $resJson['htmlLink'] ?? null,
                'error' => null,
            ];
        } catch (\Throwable $e) {
            Log::error("GoogleCalendarService Exception: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
