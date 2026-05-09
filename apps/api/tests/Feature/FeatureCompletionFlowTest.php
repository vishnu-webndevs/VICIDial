<?php

namespace Tests\Feature;

use App\Models\CallSession;
use App\Models\Invoice;
use App\Models\Notification;
use App\Models\ProviderAccount;
use Database\Seeders\PlanCatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeatureCompletionFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(PlanCatalogSeeder::class);
    }

    public function test_owner_can_manage_failover_policy_and_voice_profile(): void
    {
        $register = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Feature Tenant',
            'first_name' => 'Feature',
            'last_name' => 'Owner',
            'email' => 'feature-owner@wnd.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'timezone' => 'UTC',
        ])->assertCreated();

        $tenantId = $register->json('data.tenant.id');
        $token = $register->json('data.token');

        $providerA = $this->postJson('/api/v1/providers', [
            'provider_type' => 'twilio',
            'display_name' => 'Provider A',
            'credentials' => [
                'account_sid' => 'AC123456',
                'auth_token' => 'token123',
                'from_number' => '+15005550006',
            ],
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-Id' => $tenantId,
        ])->assertCreated()->json('data.id');

        $providerB = $this->postJson('/api/v1/providers', [
            'provider_type' => 'vonage',
            'display_name' => 'Provider B',
            'credentials' => [
                'api_key' => 'key123',
                'api_secret' => 'secret123',
            ],
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-Id' => $tenantId,
        ])->assertCreated()->json('data.id');

        $this->patchJson('/api/v1/providers/failover-policy', [
            'providers' => [
                ['id' => $providerA, 'failover_priority' => 1, 'is_fallback' => false],
                ['id' => $providerB, 'failover_priority' => 2, 'is_fallback' => true],
            ],
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-Id' => $tenantId,
        ])->assertOk()
            ->assertJsonPath('data.0.id', $providerA)
            ->assertJsonPath('data.1.id', $providerB);

        $this->assertDatabaseHas('provider_accounts', [
            'id' => $providerA,
            'failover_priority' => 1,
            'is_fallback' => false,
        ]);
        $this->assertDatabaseHas('provider_accounts', [
            'id' => $providerB,
            'failover_priority' => 2,
            'is_fallback' => true,
        ]);

        $this->patchJson('/api/v1/tenant/voice-profile', [
            'default_caller_id' => '+15551234567',
            'voice_locale' => 'en-US',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-Id' => $tenantId,
        ])->assertOk()
            ->assertJsonPath('data.default_caller_id', '+15551234567')
            ->assertJsonPath('data.voice_locale', 'en-US');
    }

    public function test_owner_can_export_calls_and_use_global_search_and_notifications(): void
    {
        $register = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Search Tenant',
            'first_name' => 'Search',
            'last_name' => 'Owner',
            'email' => 'search-owner@wnd.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'timezone' => 'UTC',
        ])->assertCreated();

        $tenantId = $register->json('data.tenant.id');
        $token = $register->json('data.token');
        $userId = $register->json('data.user.id');

        $provider = ProviderAccount::query()->create([
            'tenant_id' => $tenantId,
            'provider_type' => 'twilio',
            'display_name' => 'CSV Provider',
            'credentials_encrypted' => ['account_sid' => 'ACxxx', 'auth_token' => 'tok'],
            'status' => 'active',
        ]);

        CallSession::query()->create([
            'tenant_id' => $tenantId,
            'provider_account_id' => $provider->id,
            'initiated_by' => $userId,
            'direction' => 'outbound',
            'status' => 'completed',
            'from_number' => '+15550000001',
            'to_number' => '+15550000002',
            'duration_seconds' => 44,
            'retry_count' => 0,
            'started_at' => now()->subMinute(),
            'ended_at' => now(),
        ]);

        Invoice::query()->create([
            'tenant_id' => $tenantId,
            'invoice_number' => 'INV-1001',
            'status' => 'paid',
            'subtotal_cents' => 1000,
            'tax_cents' => 100,
            'total_cents' => 1100,
            'currency' => 'USD',
        ]);

        Notification::query()->create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'type' => 'system',
            'title' => 'Quota Warning',
            'message' => 'Usage crossed 80%.',
        ]);

        $this->get('/api/v1/calls/export?limit=100', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-Id' => $tenantId,
            'Accept' => 'text/csv',
        ])->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $this->getJson('/api/v1/search?q=INV-1001', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-Id' => $tenantId,
        ])->assertOk()
            ->assertJsonCount(1, 'data');

        $notificationResponse = $this->getJson('/api/v1/notifications?unread_only=1', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-Id' => $tenantId,
        ])->assertOk();

        $notification = collect($notificationResponse->json('data'))
            ->first(fn (array $item) => ($item['title'] ?? '') === 'Quota Warning');
        $this->assertNotNull($notification);
        $notificationId = $notification['id'];
        $this->patchJson("/api/v1/notifications/{$notificationId}/read", [], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-Id' => $tenantId,
        ])->assertOk()
            ->assertJsonPath('data.read_at', fn ($value) => ! empty($value));
    }
}
