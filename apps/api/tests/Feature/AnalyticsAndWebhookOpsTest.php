<?php

namespace Tests\Feature;

use App\Models\CallEvent;
use App\Models\CallSession;
use App\Models\ProviderAccount;
use Database\Seeders\PlanCatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsAndWebhookOpsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(PlanCatalogSeeder::class);
    }

    public function test_calls_analytics_summary_endpoint_returns_expected_metrics(): void
    {
        $register = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Analytics Labs',
            'first_name' => 'Analytics',
            'last_name' => 'Owner',
            'email' => 'analytics-owner@wnd.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'timezone' => 'UTC',
        ])->assertCreated();

        $tenantId = $register->json('data.tenant.id');
        $token = $register->json('data.token');

        CallSession::query()->create([
            'tenant_id' => $tenantId,
            'direction' => 'outbound',
            'status' => 'completed',
            'provider_call_id' => 'ACALL001',
            'from_number' => '+15005550006',
            'to_number' => '+14155550101',
            'duration_seconds' => 120,
            'created_at' => now()->subMinutes(10),
        ]);

        CallSession::query()->create([
            'tenant_id' => $tenantId,
            'direction' => 'outbound',
            'status' => 'failed',
            'provider_call_id' => 'ACALL002',
            'from_number' => '+15005550006',
            'to_number' => '+14155550102',
            'duration_seconds' => 0,
            'created_at' => now()->subMinutes(8),
        ]);

        $this->getJson('/api/v1/analytics/calls?from='.now()->subDay()->toDateString().'&to='.now()->toDateString(), [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-Id' => $tenantId,
        ])->assertOk()
            ->assertJsonPath('data.summary.total_calls', 2)
            ->assertJsonPath('data.summary.completed', 1)
            ->assertJsonPath('data.summary.failed', 1)
            ->assertJsonPath('data.summary.total_duration_seconds', 120);
    }

    public function test_webhook_overview_and_replay_endpoints_work_for_owner(): void
    {
        $register = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Webhook Ops Labs',
            'first_name' => 'Webhook',
            'last_name' => 'Owner',
            'email' => 'webhook-ops-owner@wnd.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'timezone' => 'UTC',
        ])->assertCreated();

        $tenantId = $register->json('data.tenant.id');
        $token = $register->json('data.token');

        $provider = ProviderAccount::query()->create([
            'tenant_id' => $tenantId,
            'provider_type' => 'twilio',
            'display_name' => 'Ops Twilio',
            'credentials_encrypted' => [
                'account_sid' => 'ACOPS123',
                'auth_token' => 'token',
            ],
            'status' => 'active',
        ]);

        $call = CallSession::query()->create([
            'tenant_id' => $tenantId,
            'provider_account_id' => $provider->id,
            'direction' => 'outbound',
            'status' => 'queued',
            'provider_call_id' => 'WOPS001',
            'from_number' => '+15005550006',
            'to_number' => '+14155550999',
        ]);

        $event = CallEvent::query()->create([
            'tenant_id' => $tenantId,
            'call_session_id' => $call->id,
            'provider_account_id' => $provider->id,
            'event_type' => 'call.failed',
            'provider_event_type' => 'no-answer',
            'status_after' => 'failed',
            'payload' => ['error_message' => 'No answer'],
            'occurred_at' => now(),
        ]);

        $this->getJson('/api/v1/webhooks/overview', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-Id' => $tenantId,
        ])->assertOk()
            ->assertJsonPath('data.active_provider_accounts.twilio', 1)
            ->assertJsonPath('data.metrics.provider_total', 1);

        $this->postJson('/api/v1/webhooks/replay', [
            'source' => 'provider',
            'id' => $event->id,
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-Id' => $tenantId,
        ])->assertStatus(202)
            ->assertJsonPath('data.source', 'provider');

        $call->refresh();
        $this->assertSame('failed', $call->status);
        $this->assertDatabaseHas('call_events', [
            'call_session_id' => $call->id,
            'event_type' => 'webhook.replay',
        ]);
    }
}
