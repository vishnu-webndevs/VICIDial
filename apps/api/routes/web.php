<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/storage/chat_attachments/{filename}', function ($filename) {
    $cleanFilename = basename($filename);
    $path = storage_path('app/public/chat_attachments/' . $cleanFilename);
    if (!file_exists($path)) {
        abort(404);
    }
    $ext = strtolower(pathinfo($cleanFilename, PATHINFO_EXTENSION));
    $knownMimes = [
        'ogg'  => 'audio/ogg',
        'opus' => 'audio/ogg',
        'mp3'  => 'audio/mpeg',
        'm4a'  => 'audio/mp4',
        'aac'  => 'audio/aac',
        'wav'  => 'audio/wav',
        'amr'  => 'audio/amr',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
        'gif'  => 'image/gif',
        'mp4'  => 'video/mp4',
        'pdf'  => 'application/pdf',
        'txt'  => 'text/plain',
    ];
    $mime = $knownMimes[$ext] ?? (mime_content_type($path) ?: 'application/octet-stream');

    return response()->file($path, [
        'Content-Type' => $mime,
        'Accept-Ranges' => 'bytes',
        'Access-Control-Allow-Origin' => '*',
    ]);
});
