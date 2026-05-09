<?php

namespace Tests\Feature;

use App\Models\ProviderAccount;
use Database\Seeders\PlanCatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderAndApiTokenFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(PlanCatalogSeeder::class);
    }

    public function test_owner_can_register_and_test_provider_account(): void
    {
        $register = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Provider Labs',
            'first_name' => 'Provider',
            'last_name' => 'Owner',
            'email' => 'provider-owner@wnd.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'timezone' => 'UTC',
        ])->assertCreated();

        $tenantId = $register->json('data.tenant.id');
        $token = $register->json('data.token');

        $create = $this->postJson('/api/v1/providers', [
            'provider_type' => 'twilio',
            'display_name' => 'Primary Twilio',
            'credentials' => [
                'account_sid' => 'AC123456',
                'auth_token' => 'token123',
                'from_number' => '+15005550006',
            ],
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-Id' => $tenantId,
        ])->assertCreated()
            ->assertJsonPath('data.provider_type', 'twilio')
            ->assertJsonPath('data.credentials_masked', true);

        $providerId = $create->json('data.id');

        $this->postJson("/api/v1/providers/{$providerId}/test-connection", [], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-Id' => $tenantId,
        ])->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.test_result.ok', true);

        $provider = ProviderAccount::query()->where('id', $providerId)->firstOrFail();
        $this->assertSame('active', $provider->status);
        $this->assertNotNull($provider->last_tested_at);
    }

    public function test_owner_can_update_and_delete_provider_account(): void
    {
        $register = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Provider Edit Labs',
            'first_name' => 'Provider',
            'last_name' => 'Editor',
            'email' => 'provider-editor@wnd.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'timezone' => 'UTC',
        ])->assertCreated();

        $tenantId = $register->json('data.tenant.id');
        $token = $register->json('data.token');

        $create = $this->postJson('/api/v1/providers', [
            'provider_type' => 'twilio',
            'display_name' => 'Legacy Twilio',
            'credentials' => [
                'account_sid' => 'AC123456',
                'auth_token' => 'token123',
                'from_number' => '+15005550006',
            ],
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-Id' => $tenantId,
        ])->assertCreated();

        $providerId = $create->json('data.id');

        $this->patchJson("/api/v1/providers/{$providerId}", [
            'display_name' => 'Updated Twilio',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-Id' => $tenantId,
        ])->assertOk()
            ->assertJsonPath('data.id', $providerId)
            ->assertJsonPath('data.display_name', 'Updated Twilio');

        $this->deleteJson("/api/v1/providers/{$providerId}", [], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-Id' => $tenantId,
        ])->assertOk()
            ->assertJsonPath('data.id', $providerId)
            ->assertJsonPath('data.deleted', true);

        $this->assertDatabaseMissing('provider_accounts', [
            'id' => $providerId,
            'tenant_id' => $tenantId,
        ]);
    }

    public function test_provider_partial_credential_update_keeps_existing_fields(): void
    {
        $register = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Provider Merge Labs',
            'first_name' => 'Provider',
            'last_name' => 'Merge',
            'email' => 'provider-merge@wnd.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'timezone' => 'UTC',
        ])->assertCreated();

        $tenantId = $register->json('data.tenant.id');
        $token = $register->json('data.token');

        $create = $this->postJson('/api/v1/providers', [
            'provider_type' => 'twilio',
            'display_name' => 'Merge Twilio',
            'credentials' => [
                'account_sid' => 'ACOLD123456',
                'auth_token' => 'old-token',
                'from_number' => '+15005550006',
            ],
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-Id' => $tenantId,
        ])->assertCreated();

        $providerId = $create->json('data.id');

        $this->patchJson("/api/v1/providers/{$providerId}", [
            'credentials' => [
                'account_sid' => 'ACNEW123456',
                'auth_token' => 'new-token',
                'from_number' => '',
            ],
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-Id' => $tenantId,
        ])->assertOk();

        $provider = ProviderAccount::query()->where('id', $providerId)->firstOrFail();
        $credentials = (array) $provider->credentials_encrypted;
        $this->assertSame('ACNEW123456', $credentials['account_sid'] ?? null);
        $this->assertSame('new-token', $credentials['auth_token'] ?? null);
        $this->assertSame('+15005550006', $credentials['from_number'] ?? null);
    }

    public function test_owner_can_create_list_and_revoke_tenant_api_token(): void
    {
        $register = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Token Labs',
            'first_name' => 'Token',
            'last_name' => 'Owner',
            'email' => 'token-owner@wnd.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'timezone' => 'UTC',
        ])->assertCreated();

        $tenantId = $register->json('data.tenant.id');
        $token = $register->json('data.token');

        $created = $this->postJson('/api/v1/api-tokens', [
            'name' => 'automation',
            'abilities' => ['calls:write', 'calls:read'],
            'expires_in_days' => 30,
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-Id' => $tenantId,
        ])->assertCreated()
            ->assertJsonPath('data.name', 'automation');

        $tokenId = $created->json('data.id');
        $this->assertNotEmpty($created->json('data.token'));

        $this->getJson('/api/v1/api-tokens', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-Id' => $tenantId,
        ])->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $tokenId)
            ->assertJsonPath('data.0.name', 'automation');

        $this->deleteJson("/api/v1/api-tokens/{$tokenId}", [], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-Id' => $tenantId,
        ])->assertNoContent();

        $this->getJson('/api/v1/api-tokens', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-Id' => $tenantId,
        ])->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
