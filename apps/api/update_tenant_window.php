<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$tenantId = '019dfbb0-2480-7008-963c-55a0c091425e';
$setting = \App\Models\TenantSetting::where('tenant_id', $tenantId)->first();
if ($setting) {
    $metadata = (array) ($setting->metadata ?? []);
    $metadata['calling_window'] = [
        'days' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'],
        'start_time' => '11:00',
        'end_time' => '18:00',
        'timezone' => 'Asia/Kolkata'
    ];
    $setting->metadata = $metadata;
    $setting->save();
    echo "Successfully updated calling window for tenant: $tenantId\n";
} else {
    echo "Tenant setting not found.\n";
}
