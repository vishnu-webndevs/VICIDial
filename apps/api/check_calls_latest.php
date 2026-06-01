<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$calls = \App\Models\CallSession::latest()->limit(5)->get();

foreach ($calls as $call) {
    echo "ID: {$call->id} | Status: {$call->status} | Provider Call ID: {$call->provider_call_id}\n";
}
