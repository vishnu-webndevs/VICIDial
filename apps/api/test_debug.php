<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\Campaigns\OutboundDialerService;

$service = new OutboundDialerService();

// Use Reflection to access private method debugReport
$reflection = new ReflectionClass($service);
$method = $reflection->getMethod('debugReport');
$method->setAccessible(true);

echo "Testing debugReport()...\n";

try {
    $method->invokeArgs($service, [
        'hypothesisId' => 'test-hyp',
        'event' => 'test.event',
        'data' => ['key' => 'value', 'status' => 'testing']
    ]);
    echo "debugReport() called successfully.\n";
} catch (\Exception $e) {
    echo "Error calling debugReport(): " . $e->getMessage() . "\n";
}
