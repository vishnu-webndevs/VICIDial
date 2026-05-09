<?php

declare(strict_types=1);

use App\Models\Membership;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$email = 'superadmin@vicidial.local';
$password = 'SuperAdmin@123';

$user = User::query()->updateOrCreate(
    ['email' => $email],
    [
        'first_name' => 'Super',
        'last_name' => 'Admin',
        'password' => Hash::make($password),
        'is_platform_admin' => true,
    ]
);

$role = Role::query()->firstOrCreate(
    ['slug' => 'super_admin'],
    [
        'name' => 'Super Admin',
        'description' => 'Super Admin role',
        'is_platform_role' => true,
        'hierarchy_level' => 1,
    ]
);

$tenant = Tenant::query()->where('slug', 'system-tenant')->first() ?? Tenant::query()->first();
if ($tenant) {
    Membership::query()->updateOrCreate(
        ['tenant_id' => $tenant->id, 'user_id' => $user->id],
        ['role_id' => $role->id, 'status' => 'active', 'joined_at' => now()]
    );
}

echo json_encode([
    'email' => $email,
    'password' => $password,
    'user_id' => $user->id,
    'tenant_id' => $tenant?->id,
    'role' => $role->slug,
], JSON_PRETTY_PRINT).PHP_EOL;
