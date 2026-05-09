<?php

namespace Database\Seeders;

use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SuperAdminBootstrapSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) env('SUPERADMIN_EMAIL', 'superadmin@local.test');
        $password = (string) env('SUPERADMIN_PASSWORD', 'ChangeMe!123456');
        $firstName = (string) env('SUPERADMIN_FIRST_NAME', 'Super');
        $lastName = (string) env('SUPERADMIN_LAST_NAME', 'Admin');
        $tenantName = (string) env('SUPERADMIN_TENANT_NAME', 'System Tenant');
        $tenantSlug = (string) env('SUPERADMIN_TENANT_SLUG', 'system-tenant');

        DB::transaction(function () use ($email, $password, $firstName, $lastName, $tenantName, $tenantSlug): void {
            $role = Role::query()->where('slug', 'super_admin')->firstOrFail();

            $tenant = Tenant::query()->create([
                'name' => $tenantName,
                'slug' => Str::slug($tenantSlug),
                'status' => 'active',
            ]);

            TenantSetting::query()->create([
                'tenant_id' => $tenant->id,
                'timezone' => 'UTC',
                'locale' => 'en',
            ]);

            $user = User::query()->create([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => Str::lower($email),
                'password' => Hash::make($password),
                'is_platform_admin' => true,
            ]);

            Membership::query()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'role_id' => $role->id,
                'status' => 'active',
                'joined_at' => now(),
            ]);

            AuditLog::query()->create([
                'tenant_id' => $tenant->id,
                'actor_id' => $user->id,
                'actor_type' => 'user',
                'action' => 'system.database_reset',
                'resource_type' => 'database',
                'resource_id' => null,
                'new_values' => [
                    'mode' => 'fresh_reset_with_reference_seed',
                    'superadmin_email' => Str::lower($email),
                ],
                'ip_address' => '127.0.0.1',
                'user_agent' => 'cli-bootstrap-seeder',
            ]);
        });
    }
}
