<?php

namespace App\Services\Messaging;

use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\ProviderAccount;
use Illuminate\Support\Facades\Http;
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
}

