<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Membership;
use App\Models\Role;
use Database\Seeders\PlanCatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminCommunicationSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(PlanCatalogSeeder::class);
    }

    public function test_admin_can_sync_and_assign_numbers_within_tenant_scope(): void
    {
        $register = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Comm Settings Tenant',
            'first_name' => 'Owner',
            'last_name' => 'User',
            'email' => 'owner-comm@wnd.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'timezone' => 'UTC',
        ])->assertCreated();

        $tenantId = $register->json('data.tenant.id');
        $token = $register->json('data.token');
        $provider = $this->postJson('/api/v1/providers', [
            'provider_type' => 'twilio',
            'display_name' => 'Tenant Twilio',
            'credentials' => [
                'account_sid' => 'AC123456',
                'auth_token' => 'token123',
                'from_number' => '+15005550006',
            ],
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-Id' => $tenantId,
        ])->assertCreated();

        $providerId = $provider->json('data.id');

        $agent = $this->postJson('/api/v1/agents', [
            'company_number' => '1001',
            'status' => 'active',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-Id' => $tenantId,
        ])->assertCreated();
        $agentId = $agent->json('data.id');

        $sync = $this->postJson("/api/v1/admin/settings/communication/providers/{$providerId}/numbers/sync", [
            'numbers' => [
                [
                    'sid' => 'PN123',
                    'phone_number' => '+15005550006',
                    'friendly_name' => 'Primary',
                    'capabilities' => ['voice' => true, 'sms' => true],
                ],
            ],
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-Id' => $tenantId,
        ])->assertOk();

        $numberId = $sync->json('data.numbers.0.id');
        $this->assertNotEmpty($numberId);

        $this->postJson("/api/v1/admin/settings/communication/providers/{$providerId}/test", [
            'provider_phone_number_id' => $numberId,
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-Id' => $tenantId,
        ])->assertOk()
            ->assertJsonPath('data.provider_test_result.ok', true)
            ->assertJsonPath('data.number_test_result.ok', true);

        $this->postJson('/api/v1/admin/settings/communication/agents/number-assignments', [
            'agent_id' => $agentId,
            'provider_phone_number_id' => $numberId,
            'status' => 'active',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-Id' => $tenantId,
        ])->assertCreated()
            ->assertJsonPath('data.agent.id', $agentId)
            ->assertJsonPath('data.number.id', $numberId);

        $this->getJson('/api/v1/admin/settings/communication/numbers/validated', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-Id' => $tenantId,
        ])->assertOk()
            ->assertJsonPath('data.0.id', $numberId);

        $this->assertDatabaseHas('provider_phone_numbers', [
            'id' => $numberId,
            'tenant_id' => $tenantId,
            'is_validated' => true,
            'status' => 'active',
        ]);
    }

    public function test_non_admin_member_is_blocked_from_admin_communication_settings(): void
    {
        $register = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Restricted Comm Tenant',
            'first_name' => 'Owner',
            'last_name' => 'User',
            'email' => 'owner-restricted@wnd.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'timezone' => 'UTC',
        ])->assertCreated();

        $tenantId = $register->json('data.tenant.id');
        $teamUser = User::query()->create([
            'first_name' => 'Team',
            'last_name' => 'Member',
            'email' => 'team-member@wnd.test',
            'password' => Hash::make('password123'),
        ]);
        $teamRole = Role::query()->where('slug', 'team')->firstOrFail();
        Membership::query()->create([
            'tenant_id' => $tenantId,
            'user_id' => $teamUser->id,
            'role_id' => $teamRole->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $teamToken = $teamUser->createToken('auth-token')->plainTextToken;

        $this->getJson('/api/v1/admin/settings/communication', [
            'Authorization' => "Bearer {$teamToken}",
            'X-Tenant-Id' => $tenantId,
        ])->assertForbidden()
            ->assertJsonPath('error.code', 'AUTH_FORBIDDEN');
    }

    public function test_team_member_can_list_agent_validated_numbers_without_admin_access(): void
    {
        $register = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Agent Validated Numbers Tenant',
            'first_name' => 'Owner',
            'last_name' => 'User',
            'email' => 'owner-agent-validated@wnd.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'timezone' => 'UTC',
        ])->assertCreated();

        $tenantId = $register->json('data.tenant.id');
        $ownerToken = $register->json('data.token');

        $provider = $this->postJson('/api/v1/providers', [
            'provider_type' => 'twilio',
            'display_name' => 'Tenant Twilio',
            'credentials' => [
                'account_sid' => 'AC123456',
                'auth_token' => 'token123',
                'from_number' => '+15005550006',
            ],
        ], [
            'Authorization' => "Bearer {$ownerToken}",
            'X-Tenant-Id' => $tenantId,
        ])->assertCreated();

        $providerId = $provider->json('data.id');
        $sync = $this->postJson("/api/v1/admin/settings/communication/providers/{$providerId}/numbers/sync", [
            'numbers' => [
                [
                    'sid' => 'PNTEAM1',
                    'phone_number' => '+15005550007',
                    'friendly_name' => 'Team Visible',
                    'capabilities' => ['voice' => true, 'sms' => true],
                ],
            ],
        ], [
            'Authorization' => "Bearer {$ownerToken}",
            'X-Tenant-Id' => $tenantId,
        ])->assertOk();

        $numberId = $sync->json('data.numbers.0.id');
        $this->postJson("/api/v1/admin/settings/communication/providers/{$providerId}/test", [
            'provider_phone_number_id' => $numberId,
        ], [
            'Authorization' => "Bearer {$ownerToken}",
            'X-Tenant-Id' => $tenantId,
        ])->assertOk();

        $teamUser = User::query()->create([
            'first_name' => 'Team',
            'last_name' => 'Member',
            'email' => 'team-agent-view@wnd.test',
            'password' => Hash::make('password123'),
        ]);
        $teamRole = Role::query()->where('slug', 'team')->firstOrFail();
        Membership::query()->create([
            'tenant_id' => $tenantId,
            'user_id' => $teamUser->id,
            'role_id' => $teamRole->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $teamToken = $teamUser->createToken('auth-token')->plainTextToken;

        $this->getJson('/api/v1/agents/validated-numbers', [
            'Authorization' => "Bearer {$teamToken}",
            'X-Tenant-Id' => $tenantId,
        ])->assertOk()
            ->assertJsonPath('data.0.id', $numberId)
            ->assertJsonPath('data.0.is_validated', true);

        $this->getJson('/api/v1/admin/settings/communication/numbers/validated', [
            'Authorization' => "Bearer {$teamToken}",
            'X-Tenant-Id' => $tenantId,
        ])->assertOk()
            ->assertJsonPath('data.0.id', $numberId);
    }
}
