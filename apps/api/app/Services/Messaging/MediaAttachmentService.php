<?php

namespace App\Services\Messaging;

use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\ProviderAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaAttachmentService
{
    public function ingestTwilioInboundMedia(ProviderAccount $provider, Message $message, array $payload): array
    {
        $credentials = (array) $provider->credentials_encrypted;
        $sid = (string) ($credentials['account_sid'] ?? '');
        $token = (string) ($credentials['auth_token'] ?? '');
        if ($sid === '' || $token === '') {
            return [];
        }

        $numMedia = (int) ($payload['NumMedia'] ?? 0);
        if ($numMedia <= 0) {
            return [];
        }

        $attachments = [];
        for ($i = 0; $i < $numMedia; $i++) {
            $url = (string) ($payload['MediaUrl'.$i] ?? '');
            if ($url === '') {
                continue;
            }
            $contentType = (string) ($payload['MediaContentType'.$i] ?? '');

            $attachment = MessageAttachment::query()->create([
                'tenant_id' => $provider->tenant_id,
                'message_id' => $message->id,
                'provider' => 'twilio',
                'provider_url' => $url,
                'content_type' => $contentType !== '' ? $contentType : null,
                'scan_status' => 'pending',
            ]);

            $download = Http::timeout(20)
                ->withBasicAuth($sid, $token)
                ->get($url);

            if (! $download->successful()) {
                $attachment->scan_status = 'failed';
                $attachment->scan_result = ['code' => 'DOWNLOAD_FAILED', 'status' => $download->status()];
                $attachment->save();
                $attachments[] = $attachment;
                continue;
            }

            $bytes = (string) $download->body();
            $sha = hash('sha256', $bytes);
            $fileName = 'media_'.$i.'_'.Str::lower(Str::random(10));
            $path = 'attachments/'.$provider->tenant_id.'/'.$message->id.'/'.$fileName;
            Storage::disk('local')->put($path, $bytes);

            $finalContentType = $contentType !== '' ? $contentType : (string) ($download->header('content-type') ?? '');
            $allowed = $this->isAllowedContentType($finalContentType);

            $attachment->content_type = $finalContentType !== '' ? $finalContentType : null;
            $attachment->storage_path = $path;
            $attachment->size_bytes = strlen($bytes);
            $attachment->sha256 = $sha;
            $attachment->scan_status = $allowed ? 'clean' : 'blocked';
            $attachment->scan_result = [
                'allowed' => $allowed,
                'content_type' => $finalContentType,
            ];
            $attachment->save();
            $attachments[] = $attachment;
        }

        return $attachments;
    }

    private function isAllowedContentType(string $contentType): bool
    {
        $value = strtolower(trim($contentType));
        if ($value === '') {
            return false;
        }

        return Str::startsWith($value, 'image/')
            || Str::startsWith($value, 'audio/')
            || Str::startsWith($value, 'video/')
            || $value === 'application/pdf'
            || $value === 'text/plain';
    }

    /**
     * Download media from Meta WhatsApp Cloud API and save to public storage
     */
    public function downloadMetaWhatsappMedia(ProviderAccount $provider, string $mediaId, string $mimeType = ''): ?string
    {
        $credentials = (array) ($provider->credentials_encrypted ?? []);
        $metaToken = (string) ($credentials['meta_access_token'] ?? env('META_WHATSAPP_TOKEN', ''));
        if ($mediaId === '' || $metaToken === '') {
            Log::warning("MediaAttachmentService: Missing mediaId or meta_access_token for Meta WhatsApp media download.");
            return null;
        }

        try {
            // Ensure public chat_attachments directory exists
            Storage::disk('public')->makeDirectory('chat_attachments');

            // 1. Check if already downloaded locally
            $cleanId = preg_replace('/[^a-zA-Z0-9_-]/', '', $mediaId);
            $existingFiles = glob(storage_path('app/public/chat_attachments/wa_' . $cleanId . '_*'));
            if (!empty($existingFiles)) {
                $existingFileName = basename($existingFiles[0]);
                return url('storage/chat_attachments/' . $existingFileName);
            }

            // 2. Query Meta Graph API for lookaside URL
            $urlResponse = Http::timeout(15)
                ->withToken($metaToken)
                ->get("https://graph.facebook.com/v21.0/{$mediaId}");

            if (!$urlResponse->successful()) {
                Log::warning("MediaAttachmentService: Meta Graph API failed for media {$mediaId} with status {$urlResponse->status()}: " . $urlResponse->body());
                return null;
            }

            $downloadUrl = (string) $urlResponse->json('url');
            $detectedMime = (string) ($urlResponse->json('mime_type') ?: $mimeType);
            if ($downloadUrl === '') {
                return null;
            }

            // 3. Download binary media from Lookaside CDN (requires Bearer token and User-Agent)
            $fileResponse = Http::timeout(30)
                ->withToken($metaToken)
                ->withHeaders([
                    'User-Agent' => 'curl/7.64.1',
                ])
                ->get($downloadUrl);

            if (!$fileResponse->successful()) {
                Log::warning("MediaAttachmentService: Meta Lookaside media download failed ({$fileResponse->status()}) for media {$mediaId}");
                return null;
            }

            $bytes = $fileResponse->body();
            if (empty($bytes)) {
                return null;
            }

            $cleanMime = strtolower(trim(explode(';', $detectedMime)[0]));
            $extension = match ($cleanMime) {
                'image/jpeg', 'image/jpg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/gif' => 'gif',
                'video/mp4' => 'mp4',
                'video/3gpp' => '3gp',
                'audio/ogg', 'audio/ogg; codecs=opus' => 'ogg',
                'audio/mpeg', 'audio/mp3' => 'mp3',
                'audio/amr' => 'amr',
                'application/pdf' => 'pdf',
                'text/plain' => 'txt',
                default => 'jpg',
            };

            $fileName = 'wa_' . $cleanId . '_' . time() . '.' . $extension;
            $storagePath = 'chat_attachments/' . $fileName;
            Storage::disk('public')->put($storagePath, $bytes);

            return url('storage/' . $storagePath);
        } catch (\Throwable $e) {
            Log::error("MediaAttachmentService exception downloading media {$mediaId}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Resolve media URLs for a message if missing from DB, backfilling from Meta WhatsApp if available
     */
    public function resolveMessageMedia(Message $message): array
    {
        $media = (array) ($message->media ?? []);
        if (!empty($media)) {
            return $media;
        }

        // Check metadata for media_id or webhook payload
        $metadata = (array) ($message->metadata ?? []);
        $mediaId = (string) ($metadata['media_id'] ?? '');
        $mimeType = (string) ($metadata['mime_type'] ?? '');

        if ($mediaId === '') {
            // Check meta webhook payload stored in metadata
            $metaPayload = (array) ($metadata['meta'] ?? []);
            $messages = (array) data_get($metaPayload, 'entry.0.changes.0.value.messages', []);
            foreach ($messages as $m) {
                $type = (string) ($m['type'] ?? '');
                if (in_array($type, ['image', 'video', 'document', 'audio', 'voice', 'sticker'], true)) {
                    $mediaObj = (array) ($m[$type === 'voice' ? 'audio' : $type] ?? []);
                    $mediaId = (string) ($mediaObj['id'] ?? '');
                    $mimeType = (string) ($mediaObj['mime_type'] ?? '');
                    if ($mediaId !== '') {
                        break;
                    }
                }
            }
        }

        if ($mediaId !== '') {
            $providerId = (string) ($metadata['provider_account_id'] ?? '');
            $provider = $providerId ? ProviderAccount::find($providerId) : null;
            if (!$provider) {
                $provider = ProviderAccount::query()
                    ->where('tenant_id', $message->tenant_id)
                    ->where('provider_type', 'meta_whatsapp')
                    ->where('status', 'active')
                    ->latest('created_at')
                    ->first();
            }

            if ($provider) {
                $downloadedUrl = $this->downloadMetaWhatsappMedia($provider, $mediaId, $mimeType);
                if ($downloadedUrl) {
                    $message->media = [$downloadedUrl];
                    $message->save();
                    return [$downloadedUrl];
                }
            }
        }

        return [];
    }
}

