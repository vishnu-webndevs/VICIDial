<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PlanCatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrgHierarchyRbacTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(PlanCatalogSeeder::class);
    }

    public function test_agency_scope_is_enforced_for_org_units_and_team_visibility(): void
    {
        $register = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Org Tenant',
            'first_name' => 'Org',
            'last_name' => 'Owner',
            'email' => 'org-owner@wnd.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'timezone' => 'UTC',
        ])->assertCreated();
        $tenantId = $register->json('data.tenant.id');
        $ownerUser = User::query()->where('email', 'org-owner@wnd.test')->firstOrFail();

        $this->actingAs($ownerUser);
        $agencyAId = $this->postJson('/api/v1/org/units', [
            'type' => 'agency',
            'name' => 'Agency A',
        ], [
            'X-Tenant-Id' => $tenantId,
        ])->assertCreated()->json('data.id');

        $agencyBId = $this->postJson('/api/v1/org/units', [
            'type' => 'agency',
            'name' => 'Agency B',
        ], [
            'X-Tenant-Id' => $tenantId,
        ])->assertCreated()->json('data.id');

        $agencyRoleId = Role::query()->where('slug', 'agency')->value('id');
        $agencyUser = User::query()->create([
            'first_name' => 'Agency',
            'last_name' => 'User',
            'email' => 'agency-user@wnd.test',
            'password' => 'password123',
        ]);
        Membership::query()->create([
            'tenant_id' => $tenantId,
            'user_id' => $agencyUser->id,
            'role_id' => $agencyRoleId,
            'status' => 'active',
            'agency_unit_id' => $agencyAId,
            'joined_at' => now(),
        ]);
        $this->actingAs($agencyUser);

        $teamAId = $this->postJson('/api/v1/org/units', [
            'type' => 'team',
            'name' => 'Agency A Team',
            'parent_id' => $agencyAId,
        ], [
            'X-Tenant-Id' => $tenantId,
        ])->assertCreated()->json('data.id');

        $teamInviteA = $this->postJson('/api/v1/team/invitations', [
            'email' => 'team-a@wnd.test',
            'role' => 'team',
            'agency_unit_id' => $agencyAId,
            'team_unit_id' => $teamAId,
        ], [
            'X-Tenant-Id' => $tenantId,
        ])->assertCreated();
        $this->postJson('/api/v1/team/invitations/'.$teamInviteA->json('data.invitation_token').'/accept', [
            'email' => 'team-a@wnd.test',
            'first_name' => 'Team',
            'last_name' => 'A',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertOk();

        $this->actingAs($ownerUser);
        $teamBInvite = $this->postJson('/api/v1/team/invitations', [
            'email' => 'team-b@wnd.test',
            'role' => 'team',
            'agency_unit_id' => $agencyBId,
        ], [
            'X-Tenant-Id' => $tenantId,
        ])->assertCreated();
        $this->postJson('/api/v1/team/invitations/'.$teamBInvite->json('data.invitation_token').'/accept', [
            'email' => 'team-b@wnd.test',
            'first_name' => 'Team',
            'last_name' => 'B',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertOk();

        $this->actingAs($agencyUser);
        $teamIndex = $this->getJson('/api/v1/team/members', [
            'X-Tenant-Id' => $tenantId,
        ])->assertOk()->json('data');

        $emails = collect($teamIndex)->map(function (array $membership) {
            return $membership['user']['email'] ?? null;
        })->filter()->values()->all();

        $this->assertContains('agency-user@wnd.test', $emails);
        $this->assertContains('team-a@wnd.test', $emails);
        $this->assertNotContains('team-b@wnd.test', $emails);
    }

    public function test_cross_tenant_data_leakage_is_blocked_by_tenant_context(): void
    {
        $tenantA = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Tenant A',
            'first_name' => 'A',
            'last_name' => 'Owner',
            'email' => 'tenant-a-owner@wnd.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'timezone' => 'UTC',
        ])->assertCreated();
        $tenantAToken = $tenantA->json('data.token');
        $tenantAId = $tenantA->json('data.tenant.id');

        $tenantB = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Tenant B',
            'first_name' => 'B',
            'last_name' => 'Owner',
            'email' => 'tenant-b-owner@wnd.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'timezone' => 'UTC',
        ])->assertCreated();
        $tenantBId = $tenantB->json('data.tenant.id');

        $this->getJson('/api/v1/leads', [
            'Authorization' => "Bearer {$tenantAToken}",
            'X-Tenant-Id' => $tenantBId,
        ])->assertForbidden()
            ->assertJsonPath('error.code', 'TENANT_CONTEXT_REQUIRED');

        $this->getJson('/api/v1/leads', [
            'Authorization' => "Bearer {$tenantAToken}",
            'X-Tenant-Id' => $tenantAId,
        ])->assertOk();
    }
}
