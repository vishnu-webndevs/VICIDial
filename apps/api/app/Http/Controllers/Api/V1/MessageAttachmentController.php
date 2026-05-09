<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MessageAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MessageAttachmentController extends Controller
{
    public function download(Request $request, string $id): StreamedResponse
    {
        $tenant = $request->attributes->get('tenant');
        $attachment = MessageAttachment::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        $path = (string) ($attachment->storage_path ?? '');
        if ($path === '' || ! Storage::disk('local')->exists($path)) {
            abort(404);
        }

        $fileName = (string) ($attachment->file_name ?? basename($path));
        $contentType = (string) ($attachment->content_type ?? 'application/octet-stream');

        return response()->streamDownload(function () use ($path) {
            echo Storage::disk('local')->get($path);
        }, $fileName, [
            'Content-Type' => $contentType,
        ]);
    }
}

