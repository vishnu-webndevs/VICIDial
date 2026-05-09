<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Keep seeding idempotent even if hierarchy levels changed across versions.
        Role::query()->update([
            'hierarchy_level' => DB::raw('hierarchy_level + 1000'),
        ]);

        $roles = [
            ['slug' => 'platform_super_admin', 'name' => 'Platform Super Admin', 'is_platform_role' => true, 'hierarchy_level' => 0],
            ['slug' => 'super_admin', 'name' => 'Super Admin', 'is_platform_role' => true, 'hierarchy_level' => 1],
            ['slug' => 'admin', 'name' => 'Admin', 'is_platform_role' => false, 'hierarchy_level' => 2],
            ['slug' => 'agency', 'name' => 'Agency', 'is_platform_role' => false, 'hierarchy_level' => 3],
            ['slug' => 'team', 'name' => 'Team', 'is_platform_role' => false, 'hierarchy_level' => 4],
            ['slug' => 'company_owner', 'name' => 'Company Owner', 'is_platform_role' => false, 'hierarchy_level' => 5],
            ['slug' => 'company_admin', 'name' => 'Company Admin', 'is_platform_role' => false, 'hierarchy_level' => 6],
            ['slug' => 'billing_manager', 'name' => 'Billing Manager', 'is_platform_role' => false, 'hierarchy_level' => 7],
            ['slug' => 'developer_manager', 'name' => 'Developer Manager', 'is_platform_role' => false, 'hierarchy_level' => 8],
            ['slug' => 'operations_manager', 'name' => 'Operations Manager', 'is_platform_role' => false, 'hierarchy_level' => 9],
            ['slug' => 'support_analyst', 'name' => 'Support Analyst', 'is_platform_role' => false, 'hierarchy_level' => 10],
        ];

        foreach ($roles as $role) {
            Role::query()->updateOrCreate(
                ['slug' => $role['slug']],
                [
                    'name' => $role['name'],
                    'description' => $role['name'].' role',
                    'is_platform_role' => $role['is_platform_role'],
                    'hierarchy_level' => $role['hierarchy_level'],
                ]
            );
        }

        $permissionsByModule = [
            'tenant' => ['tenant.view', 'tenant.update', 'team.view', 'team.invite', 'team.update', 'team.remove', 'role.assign', 'audit.view'],
            'billing' => ['billing.view', 'billing.manage', 'usage.view', 'invoice.view', 'invoice.export'],
            'provider' => ['provider.view', 'provider.create', 'provider.update', 'provider.delete', 'provider.test', 'credential.update', 'credential.view_masked'],
            'agent' => ['agent.view', 'agent.create', 'agent.update', 'agent.delete', 'agent.assign'],
            'calling' => ['call.initiate', 'call.view', 'call.retry', 'call.export'],
            'webhook' => ['webhook.view', 'webhook.create', 'webhook.update', 'webhook.delete'],
            'failover' => ['failover.view', 'failover.manage', 'voice_profile.view', 'voice_profile.manage'],
            'analytics' => ['analytics.view', 'analytics.export'],
            'api_token' => ['api_token.view', 'api_token.create', 'api_token.revoke'],
            'platform' => ['platform.tenant_list', 'platform.tenant_switch', 'platform.tenant_suspend', 'platform.tenant_reactivate', 'platform.plan_manage', 'platform.analytics'],
        ];

        $planGated = [
            'failover.view',
            'failover.manage',
            'analytics.export',
            'call.export',
            'invoice.export',
            'api_token.create',
            'webhook.create',
        ];

        foreach ($permissionsByModule as $module => $permissionSlugs) {
            foreach ($permissionSlugs as $slug) {
                Permission::query()->updateOrCreate(
                    ['slug' => $slug],
                    [
                        'name' => $slug,
                        'module' => $module,
                        'description' => $slug.' permission',
                        'is_plan_gated' => in_array($slug, $planGated, true),
                    ]
                );
            }
        }

        $allPermissions = Permission::query()->pluck('id', 'slug')->all();
        $rolePermissions = [
            'platform_super_admin' => array_keys($allPermissions),
            'super_admin' => array_keys($allPermissions),
            'admin' => array_filter(array_keys($allPermissions), fn (string $slug) => ! str_starts_with($slug, 'platform.')),
            'agency' => [
                'tenant.view', 'tenant.update', 'team.view', 'team.invite', 'team.update', 'team.remove', 'role.assign', 'audit.view',
                'provider.view', 'provider.create', 'provider.update', 'provider.test',
                'agent.view', 'agent.create', 'agent.update', 'agent.assign',
                'call.initiate', 'call.view', 'call.retry',
                'webhook.view', 'webhook.create', 'webhook.update',
                'analytics.view',
                'api_token.view', 'api_token.create',
            ],
            'team' => [
                'tenant.view',
                'call.initiate',
                'call.view',
                'agent.view',
                'analytics.view',
            ],
            'company_owner' => array_filter(array_keys($allPermissions), fn (string $slug) => ! str_starts_with($slug, 'platform.')),
            'company_admin' => [
                'tenant.view', 'tenant.update', 'team.view', 'team.invite', 'team.update', 'team.remove', 'role.assign', 'audit.view',
                'provider.view', 'provider.create', 'provider.update', 'provider.delete', 'provider.test', 'credential.update', 'credential.view_masked',
                'agent.view', 'agent.create', 'agent.update', 'agent.delete', 'agent.assign',
                'call.initiate', 'call.view', 'call.retry', 'call.export',
                'webhook.view', 'webhook.create', 'webhook.update', 'webhook.delete',
                'failover.view', 'failover.manage', 'voice_profile.view', 'voice_profile.manage',
                'analytics.view', 'analytics.export',
                'api_token.view', 'api_token.create', 'api_token.revoke',
                'usage.view',
            ],
            'billing_manager' => ['billing.view', 'billing.manage', 'usage.view', 'invoice.view', 'invoice.export', 'tenant.view'],
            'developer_manager' => [
                'tenant.view',
                'provider.view', 'provider.create', 'provider.update', 'provider.test', 'credential.update', 'credential.view_masked',
                'agent.view', 'agent.create', 'agent.update', 'agent.assign',
                'call.initiate', 'call.view', 'call.retry',
                'webhook.view', 'webhook.create', 'webhook.update', 'webhook.delete',
                'api_token.view', 'api_token.create', 'api_token.revoke',
            ],
            'operations_manager' => [
                'tenant.view', 'team.view', 'audit.view',
                'provider.view', 'provider.test', 'credential.view_masked',
                'agent.view', 'agent.assign',
                'call.initiate', 'call.view', 'call.retry', 'call.export',
                'webhook.view',
                'failover.view', 'voice_profile.view',
                'analytics.view', 'analytics.export', 'usage.view',
            ],
            'support_analyst' => ['tenant.view', 'audit.view', 'provider.view', 'agent.view', 'call.view', 'analytics.view', 'usage.view'],
        ];

        foreach ($rolePermissions as $roleSlug => $slugs) {
            $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
            $ids = collect($slugs)
                ->filter(fn (string $slug) => isset($allPermissions[$slug]))
                ->map(fn (string $slug) => $allPermissions[$slug])
                ->values()
                ->all();
            $role->permissions()->sync($ids);
        }
    }
}
