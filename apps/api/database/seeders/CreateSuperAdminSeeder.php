<?php

namespace Database\Seeders;

use App\Models\Membership;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreateSuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email     = (string) env('SUPERADMIN_EMAIL',       'admin@vicidial.local');
        $password  = (string) env('SUPERADMIN_PASSWORD',    'Admin@123456');
        $firstName = (string) env('SUPERADMIN_FIRST_NAME',  'Super');
        $lastName  = (string) env('SUPERADMIN_LAST_NAME',   'Admin');
        $tenantName = (string) env('SUPERADMIN_TENANT_NAME', 'System Tenant');
        $tenantSlug = Str::slug((string) env('SUPERADMIN_TENANT_SLUG', 'system-tenant'));

        DB::transaction(function () use ($email, $password, $firstName, $lastName, $tenantName, $tenantSlug): void {

            // 1. Ensure the super_admin role exists (creates it if the
            //    RolesAndPermissionsSeeder hasn't been run yet).
            $role = Role::query()->updateOrCreate(
                ['slug' => 'super_admin'],
                [
                    'name'              => 'Super Admin',
                    'description'       => 'Super Admin role',
                    'is_platform_role'  => true,
                    'hierarchy_level'   => 1,
                ]
            );

            // 2. Create or reuse the system tenant.
            $tenant = Tenant::query()->firstOrCreate(
                ['slug' => $tenantSlug],
                [
                    'name'   => $tenantName,
                    'status' => 'active',
                ]
            );

            // Ensure tenant settings row exists.
            TenantSetting::query()->firstOrCreate(
                ['tenant_id' => $tenant->id],
                ['timezone' => 'UTC', 'locale' => 'en']
            );

            // 3. Create or update the super admin user.
            $user = User::query()->updateOrCreate(
                ['email' => Str::lower($email)],
                [
                    'first_name'        => $firstName,
                    'last_name'         => $lastName,
                    'password'          => Hash::make($password),
                    'is_platform_admin' => true,
                ]
            );

            // 4. Create or reuse the membership (avoids duplicate rows).
            Membership::query()->updateOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'user_id'   => $user->id,
                ],
                [
                    'role_id'   => $role->id,
                    'status'    => 'active',
                    'joined_at' => now(),
                ]
            );

            $this->command->info('');
            $this->command->info('  Super admin ready');
            $this->command->info("  Email    : {$email}");
            $this->command->info("  Password : {$password}");
            $this->command->info("  Tenant   : {$tenantName} ({$tenantSlug})");
            $this->command->info('');
            $this->command->warn('  Change the password immediately after first login.');
            $this->command->info('');
        });
    }
}
