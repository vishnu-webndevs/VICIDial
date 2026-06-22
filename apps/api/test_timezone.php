<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$c = \App\Models\Campaign::find('019eb56f-8c26-72f1-84a8-85f8416f30fe');
echo "Campaign Settings: " . json_encode($c->settings) . "\n";
