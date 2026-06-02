<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$settings = \DB::table('tenant_settings')->get();
foreach ($settings as $s) {
    echo "Tenant: " . $s->tenant_id . "\n";
    echo "Metadata: " . $s->metadata . "\n\n";
}
