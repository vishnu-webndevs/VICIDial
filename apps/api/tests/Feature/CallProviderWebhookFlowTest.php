<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AgentPhoneAssignment;
use App\Models\CallSession;
use App\Models\ProviderAccount;
use App\Models\ProviderPhoneNumber;
use App\Models\UsageMeter;
use Database\Seeders\PlanCatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use App\Jobs\DispatchOutboundCallJob;

class CallProviderWebhookFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(PlanCatalogSeeder::class);
    }

    public function test_owner_can_initiate_call_with_active_provider(): void
    {
        $register = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Call Labs',
            'first_name' => 'Call',
            'last_name' => 'Owner',
            'email' => 'call-owner@wnd.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'timezone' => 'UTC',
        ])->assertCreated();

        $tenantId = $register->json('data.tenant.id');
        $token = $register->json('data.token');

        $provider = ProviderAccount::query()->create([
            'tenant_id' => $tenantId,
            'provider_type' => 'twilio',
            'display_name' => 'Primary Twilio',
            'credentials_encrypted' => [
                'account_sid' => 'AC111222333',
                'auth_token' => 'twilio_secret_token',
                'from_number' => '+15005550006',
            ],
            'status' => 'active',
        ]);

        $this->postJson('/api/v1/calls', [
            'to' => '+14155551234',
            'provider_account_id' => $provider->id,
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-Id' => $tenantId,
        ])->assertStatus(202)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.provider.id', $provider->id);
    }

    public function test_twilio_webhook_normalizes_event_and_consumes_meters(): void
    {
        $register = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Webhook Call Labs',
            'first_name' => 'Webhook',
            'last_name' => 'Owner',
            'email' => 'webhook-call-owner@wnd.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'timezone' => 'UTC',
        ])->assertCreated();

        $tenantId = $register->json('data.tenant.id');

        $provider = ProviderAccount::query()->create([
            'tenant_id' => $tenantId,
            'provider_type' => 'twilio',
            'display_name' => 'Primary Twilio',
            'credentials_encrypted' => [
                'account_sid' => 'AC999888777',
                'auth_token' => 'twilio_secret_token',
                'from_number' => '+15005550006',
            ],
            'status' => 'active',
        ]);

        $call = CallSession::query()->create([
            'tenant_id' => $tenantId,
            'provider_account_id' => $provider->id,
            'direction' => 'outbound',
            'status' => 'queued',
            'provider_call_id' => 'CA123456789',
            'from_number' => '+15005550006',
            'to_number' => '+14155551234',
        ]);

        $payloadArray = [
            'AccountSid' => 'AC999888777',
            'CallSid' => 'CA123456789',
            'CallStatus' => 'completed',
            'CallDuration' => '125',
            'EventType' => 'completed',
        ];
        $signaturePayload = rtrim((string) config('app.url'), '/').'/api/webhooks/twilio';
        $sortedPayload = $payloadArray;
        ksort($sortedPayload);
        foreach ($sortedPayload as $key => $value) {
            $signaturePayload .= (string) $key.(string) $value;
        }
        $signature = base64_encode(hash_hmac('sha1', $signaturePayload, 'twilio_secret_token', true));

        $this->post('/api/webhooks/twilio', $payloadArray, [
            'HTTP_X_TWILIO_SIGNATURE' => $signature,
        ])->assertOk()
            ->assertJsonPath('received', true);

        $call->refresh();
        $this->assertSame('completed', $call->status);
        $this->assertSame(125, (int) $call->duration_seconds);

        $this->assertDatabaseHas('call_events', [
            'call_session_id' => $call->id,
            'event_type' => 'call.completed',
            'status_after' => 'completed',
        ]);

        $webhookMeter = UsageMeter::query()
            ->where('tenant_id', $tenantId)
            ->where('meter_type', 'webhook_events')
            ->firstOrFail();
        $callMinutesMeter = UsageMeter::query()
            ->where('tenant_id', $tenantId)
            ->where('meter_type', 'call_minutes')
            ->firstOrFail();

        $this->assertSame(1, (int) $webhookMeter->consumed_units);
        $this->assertSame(3, (int) $callMinutesMeter->consumed_units);
    }

    public function test_owner_can_list_and_view_call_details(): void
    {
        $register = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Call Detail Labs',
            'first_name' => 'Call',
            'last_name' => 'Viewer',
            'email' => 'call-viewer@wnd.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'timezone' => 'UTC',
        ])->assertCreated();

        $tenantId = $register->json('data.tenant.id');
        $token = $register->json('data.token');

        $provider = ProviderAccount::query()->create([
            'tenant_id' => $tenantId,
            'provider_type' => 'twilio',
            'display_name' => 'Primary Twilio',
            'credentials_encrypted' => [
                'account_sid' => 'AC444555666',
                'auth_token' => 'twilio_secret_token',
                'from_number' => '+15005550006',
            ],
            'status' => 'active',
        ]);

        $call = CallSession::query()->create([
            'tenant_id' => $tenantId,
            'provider_account_id' => $provider->id,
            'direction' => 'outbound',
            'status' => 'completed',
            'provider_call_id' => 'CADETAIL001',
            'from_number' => '+15005550006',
            'to_number' => '+14155551234',
            'duration_seconds' => 60,
            'started_at' => now()->subMinute(),
            'ended_at' => now(),
        ]);

        $this->getJson('/api/v1/calls', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-Id' => $tenantId,
        ])->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $call->id)
            ->assertJsonPath('data.0.provider.id', $provider->id);

        $this->getJson("/api/v1/calls/{$call->id}", [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-Id' => $tenantId,
        ])->assertOk()
            ->assertJsonPath('data.id', $call->id)
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_call_create_uses_agent_assignment_with_agent_id_schema(): void
    {
        $register = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Agent Assignment Labs',
            'first_name' => 'Agent',
            'last_name' => 'Owner',
            'email' => 'agent-assignment@wnd.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'timezone' => 'UTC',
        ])->assertCreated();

        $tenantId = $register->json('data.tenant.id');
        $userId = $register->json('data.user.id');
        $token = $register->json('data.token');

        $provider = ProviderAccount::query()->create([
            'tenant_id' => $tenantId,
            'provider_type' => 'twilio',
            'display_name' => 'Primary Twilio',
            'credentials_encrypted' => [
                'account_sid' => 'AC555666777',
                'auth_token' => 'twilio_secret_token',
            ],
            'status' => 'active',
        ]);

        $agent = Agent::query()->create([
            'tenant_id' => $tenantId,
            'company_number' => 'agent-main',
            'status' => 'active',
            'created_by' => $userId,
        ]);

        $number = ProviderPhoneNumber::query()->create([
            'tenant_id' => $tenantId,
            'provider_account_id' => $provider->id,
            'phone_number' => '+15005550006',
            'status' => 'active',
            'is_validated' => true,
        ]);

        AgentPhoneAssignment::query()->create([
            'tenant_id' => $tenantId,
            'agent_id' => $agent->id,
            'provider_phone_number_id' => $number->id,
            'status' => 'active',
        ]);

        $this->postJson('/api/v1/calls', [
            'to' => '+14155551234',
            'provider_account_id' => $provider->id,
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-Id' => $tenantId,
        ])->assertStatus(202)
            ->assertJsonPath('data.from_number', '+15005550006');
    }

    public function test_call_create_supports_legacy_user_id_assignment_column_without_exception(): void
    {
        $register = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Legacy Assignment Labs',
            'first_name' => 'Legacy',
            'last_name' => 'Owner',
            'email' => 'legacy-assignment@wnd.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'timezone' => 'UTC',
        ])->assertCreated();

        $tenantId = $register->json('data.tenant.id');
        $userId = $register->json('data.user.id');
        $token = $register->json('data.token');

        Schema::table('agent_phone_assignments', function (Blueprint $table): void {
            $table->uuid('user_id')->nullable()->after('tenant_id');
            $table->index(['tenant_id', 'user_id', 'status'], 'apa_tenant_user_status_idx');
        });

        $provider = ProviderAccount::query()->create([
            'tenant_id' => $tenantId,
            'provider_type' => 'twilio',
            'display_name' => 'Legacy Twilio',
            'credentials_encrypted' => [
                'account_sid' => 'AC123123123',
                'auth_token' => 'twilio_secret_token',
            ],
            'status' => 'active',
        ]);

        $number = ProviderPhoneNumber::query()->create([
            'tenant_id' => $tenantId,
            'provider_account_id' => $provider->id,
            'phone_number' => '+15005550007',
            'status' => 'active',
            'is_validated' => true,
        ]);

        DB::table('agent_phone_assignments')->insert([
            'id' => (string) str()->uuid(),
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'provider_phone_number_id' => $number->id,
            'status' => 'active',
            'assigned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/v1/calls', [
            'to' => '+14155551234',
            'provider_account_id' => $provider->id,
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-Id' => $tenantId,
        ])->assertStatus(202)
            ->assertJsonPath('data.from_number', '+15005550007');
    }

    public function test_call_create_returns_empty_assignment_result_without_throwing(): void
    {
        $register = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'No Assignment Labs',
            'first_name' => 'No',
            'last_name' => 'Assignment',
            'email' => 'no-assignment@wnd.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'timezone' => 'UTC',
        ])->assertCreated();

        $tenantId = $register->json('data.tenant.id');
        $token = $register->json('data.token');

        $provider = ProviderAccount::query()->create([
            'tenant_id' => $tenantId,
            'provider_type' => 'twilio',
            'display_name' => 'Fallback Twilio',
            'credentials_encrypted' => [
                'account_sid' => 'AC999000111',
                'auth_token' => 'twilio_secret_token',
                'from_number' => '+15005550008',
            ],
            'status' => 'active',
        ]);

        $this->postJson('/api/v1/calls', [
            'to' => '+14155551234',
            'provider_account_id' => $provider->id,
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-Id' => $tenantId,
        ])->assertStatus(202)
            ->assertJsonPath('data.from_number', '+15005550008');
    }

    public function test_retry_endpoint_and_call_create_idempotency_work(): void
    {
        Queue::fake();

        $register = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Call Retry Labs',
            'first_name' => 'Call',
            'last_name' => 'Retry',
            'email' => 'call-retry@wnd.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'timezone' => 'UTC',
        ])->assertCreated();

        $tenantId = $register->json('data.tenant.id');
        $token = $register->json('data.token');

        $provider = ProviderAccount::query()->create([
            'tenant_id' => $tenantId,
            'provider_type' => 'twilio',
            'display_name' => 'Primary Twilio',
            'credentials_encrypted' => [
                'account_sid' => 'AC777888999',
                'auth_token' => 'twilio_secret_token',
                'from_number' => '+15005550006',
            ],
            'status' => 'active',
        ]);

        $firstCreate = $this->postJson('/api/v1/calls', [
            'to' => '+14155551234',
            'provider_account_id' => $provider->id,
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-Id' => $tenantId,
            'X-Idempotency-Key' => 'idem-call-create-001',
        ])->assertStatus(202);

        $this->postJson('/api/v1/calls', [
            'to' => '+14155551234',
            'provider_account_id' => $provider->id,
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-Id' => $tenantId,
            'X-Idempotency-Key' => 'idem-call-create-001',
        ])->assertStatus(202)
            ->assertHeader('X-Idempotent-Replay', 'true')
            ->assertJsonPath('data.id', $firstCreate->json('data.id'));

        $failedCall = CallSession::query()->create([
            'tenant_id' => $tenantId,
            'provider_account_id' => $provider->id,
            'direction' => 'outbound',
            'status' => 'failed',
            'provider_call_id' => 'CAFAILED001',
            'from_number' => '+15005550006',
            'to_number' => '+14155551234',
            'failure_reason' => 'provider_error',
        ]);

        $retry = $this->postJson("/api/v1/calls/{$failedCall->id}/retry", [], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-Id' => $tenantId,
            'X-Idempotency-Key' => 'idem-call-retry-001',
        ])->assertStatus(202)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.retry_count', 1);

        $this->postJson("/api/v1/calls/{$failedCall->id}/retry", [], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-Id' => $tenantId,
            'X-Idempotency-Key' => 'idem-call-retry-001',
        ])->assertStatus(202)
            ->assertHeader('X-Idempotent-Replay', 'true')
            ->assertJsonPath('data.id', $retry->json('data.id'));

        Queue::assertPushed(DispatchOutboundCallJob::class, function (DispatchOutboundCallJob $job) use ($retry): bool {
            return $job->callSessionId === $retry->json('data.id');
        });
    }

    public function test_queued_call_can_be_manually_retried(): void
    {
        Queue::fake();

        $register = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Queued Restart Labs',
            'first_name' => 'Queued',
            'last_name' => 'Owner',
            'email' => 'queued-restart-owner@wnd.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'timezone' => 'UTC',
        ])->assertCreated();

        $tenantId = $register->json('data.tenant.id');
        $token = $register->json('data.token');

        $provider = ProviderAccount::query()->create([
            'tenant_id' => $tenantId,
            'provider_type' => 'twilio',
            'display_name' => 'Queued Restart Twilio',
            'credentials_encrypted' => [
                'account_sid' => 'AC777888000',
                'auth_token' => 'twilio_secret_token',
                'from_number' => '+15005550006',
            ],
            'status' => 'active',
        ]);

        $queuedCall = CallSession::query()->create([
            'tenant_id' => $tenantId,
            'provider_account_id' => $provider->id,
            'direction' => 'outbound',
            'status' => 'queued',
            'provider_call_id' => 'CAQUEUED001',
            'from_number' => '+15005550006',
            'to_number' => '+14155551234',
        ]);

        $retry = $this->postJson("/api/v1/calls/{$queuedCall->id}/retry", [], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-Id' => $tenantId,
            'X-Idempotency-Key' => 'idem-call-retry-queued-001',
        ])->assertStatus(202)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.retry_count', 1);

        Queue::assertPushed(DispatchOutboundCallJob::class, function (DispatchOutboundCallJob $job) use ($retry): bool {
            return $job->callSessionId === $retry->json('data.id');
        });
    }
}
