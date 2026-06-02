<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$jobs = DB::table('jobs')->get();
$counts = [];
foreach ($jobs as $j) {
    $p = json_decode($j->payload);
    $dn = $p->displayName ?? 'unknown';
    $counts[$dn] = ($counts[$dn] ?? 0) + 1;
}

print_r($counts);
