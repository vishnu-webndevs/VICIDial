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
    $mime = mime_content_type($path) ?: 'application/octet-stream';
    return response()->file($path, [
        'Content-Type' => $mime,
        'Access-Control-Allow-Origin' => '*',
    ]);
});
