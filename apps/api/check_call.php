<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$callId = '019e725a-ecca-73fa-ae24-f605e445e310';
$call = \App\Models\CallSession::find($callId);

if (!$call) {
    echo "Call session not found.\n";
    exit;
}

echo "CALL SESSION:\n";
echo "Status: {$call->status}\n";
echo "Provider Call ID: {$call->provider_call_id}\n";
echo "Runtime State: {$call->runtime_state}\n\n";

echo "EVENTS:\n";
$events = \App\Models\CallEvent::where('call_session_id', $callId)->orderBy('occurred_at')->get();
foreach ($events as $e) {
    echo "{$e->occurred_at} | Event: {$e->event_type} | Provider Event: {$e->provider_event_type} | Status After: {$e->status_after}\n";
    echo "Payload: " . json_encode($e->payload) . "\n\n";
}

echo "LEAD:\n";
$leadId = $call->metadata['lead_id'] ?? null;
if ($leadId) {
    $lead = \App\Models\Lead::find($leadId);
    if ($lead) {
        echo "Status: {$lead->status}\n";
        echo "Disposition: " . json_encode($lead->last_disposition) . "\n";
    }
}
