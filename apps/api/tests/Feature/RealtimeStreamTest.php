<?php

namespace Tests\Feature;

use App\Models\CallEvent;
use App\Models\CallSession;
use App\Models\ProviderAccount;
use Database\Seeders\PlanCatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RealtimeStreamTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(PlanCatalogSeeder::class);
        config([
            'realtime.stream.max_duration_seconds' => 0.001,
            'realtime.stream.poll_interval_microseconds' => 0,
        ]);
    }

    public function test_stream_requires_authentication(): void
    {
        $this->getJson('/api/v1/realtime/calls/stream')
            ->assertUnauthorized();
    }

    public function test_stream_returns_tenant_scoped_events(): void
    {
        $ownerA = $this->registerOwner('realtime-owner-a@wnd.test');
        $ownerB = $this->registerOwner('realtime-owner-b@wnd.test');

        $providerA = ProviderAccount::query()->create([
            'tenant_id' => $ownerA['tenant_id'],
            'provider_type' => 'twilio',
            'display_name' => 'Realtime Provider A',
            'credentials_encrypted' => [
                'account_sid' => 'ACA111',
                'auth_token' => 'token-a',
            ],
            'status' => 'active',
        ]);
        $providerB = ProviderAccount::query()->create([
            'tenant_id' => $ownerB['tenant_id'],
            'provider_type' => 'twilio',
            'display_name' => 'Realtime Provider B',
            'credentials_encrypted' => [
                'account_sid' => 'ACB222',
                'auth_token' => 'token-b',
            ],
            'status' => 'active',
        ]);

        $callA = CallSession::query()->create([
            'tenant_id' => $ownerA['tenant_id'],
            'provider_account_id' => $providerA->id,
            'direction' => 'outbound',
            'status' => 'ringing',
            'provider_call_id' => 'CAREALTIMEA',
            'from_number' => '+15005550006',
            'to_number' => '+14155551000',
        ]);
        $callB = CallSession::query()->create([
            'tenant_id' => $ownerB['tenant_id'],
            'provider_account_id' => $providerB->id,
            'direction' => 'outbound',
            'status' => 'completed',
            'provider_call_id' => 'CAREALTIMEB',
            'from_number' => '+15005550007',
            'to_number' => '+14155552000',
        ]);

        $eventA = CallEvent::query()->create([
            'tenant_id' => $ownerA['tenant_id'],
            'call_session_id' => $callA->id,
            'provider_account_id' => $providerA->id,
            'event_type' => 'call.ringing',
            'provider_event_type' => 'ringing',
            'status_after' => 'ringing',
            'payload' => ['source' => 'test-a'],
            'occurred_at' => now(),
            'created_at' => now()->subSeconds(2),
            'updated_at' => now()->subSeconds(2),
        ]);
        $eventB = CallEvent::query()->create([
            'tenant_id' => $ownerB['tenant_id'],
            'call_session_id' => $callB->id,
            'provider_account_id' => $providerB->id,
            'event_type' => 'call.completed',
            'provider_event_type' => 'completed',
            'status_after' => 'completed',
            'payload' => ['source' => 'test-b'],
            'occurred_at' => now(),
            'created_at' => now()->subSecond(),
            'updated_at' => now()->subSecond(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$ownerA['token'],
            'X-Tenant-Id' => $ownerA['tenant_id'],
        ])->get('/api/v1/realtime/calls/stream');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8');
        $stream = $response->streamedContent();

        $this->assertStringContainsString('event: stream.ready', $stream);
        $this->assertStringContainsString('event: call.status.updated', $stream);
        $this->assertStringContainsString((string) $eventA->id, $stream);
        $this->assertStringContainsString('event: stream.closed', $stream);
        $this->assertStringNotContainsString((string) $eventB->id, $stream);
    }

    public function test_stream_resumes_from_cursor_query_param(): void
    {
        $owner = $this->registerOwner('realtime-owner-cursor@wnd.test');

        $provider = ProviderAccount::query()->create([
            'tenant_id' => $owner['tenant_id'],
            'provider_type' => 'twilio',
            'display_name' => 'Realtime Provider Cursor',
            'credentials_encrypted' => [
                'account_sid' => 'ACCURSOR',
                'auth_token' => 'token-cursor',
            ],
            'status' => 'active',
        ]);

        $call = CallSession::query()->create([
            'tenant_id' => $owner['tenant_id'],
            'provider_account_id' => $provider->id,
            'direction' => 'outbound',
            'status' => 'in_progress',
            'provider_call_id' => 'CACURSOR001',
            'from_number' => '+15005550008',
            'to_number' => '+14155553000',
        ]);

        $firstTimestamp = now()->subSeconds(5);
        $first = CallEvent::query()->create([
            'tenant_id' => $owner['tenant_id'],
            'call_session_id' => $call->id,
            'provider_account_id' => $provider->id,
            'event_type' => 'call.answered',
            'provider_event_type' => 'answered',
            'status_after' => 'in_progress',
            'payload' => ['step' => 1],
            'occurred_at' => $firstTimestamp,
        ]);
        $first->forceFill([
            'created_at' => $firstTimestamp,
            'updated_at' => $firstTimestamp,
        ])->saveQuietly();
        $second = CallEvent::query()->create([
            'tenant_id' => $owner['tenant_id'],
            'call_session_id' => $call->id,
            'provider_account_id' => $provider->id,
            'event_type' => 'call.completed',
            'provider_event_type' => 'completed',
            'status_after' => 'completed',
            'payload' => ['step' => 2],
            'occurred_at' => now()->subSecond(),
        ]);
        $secondTimestamp = $firstTimestamp->copy()->addSeconds(3);
        $second->forceFill([
            'created_at' => $secondTimestamp,
            'updated_at' => $secondTimestamp,
        ])->saveQuietly();

        $first->refresh();
        $second->refresh();
        $cursor = $first->created_at?->format('Y-m-d\TH:i:s.u\Z').'|'.$first->id;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$owner['token'],
            'X-Tenant-Id' => $owner['tenant_id'],
        ])->get('/api/v1/realtime/calls/stream?cursor='.urlencode((string) $cursor));

        $response->assertOk();
        $stream = $response->streamedContent();

        $this->assertStringContainsString((string) $second->id, $stream);
        $this->assertStringNotContainsString((string) $first->id, $stream);
    }

    public function test_stream_uses_last_event_id_header_when_cursor_query_is_missing(): void
    {
        $owner = $this->registerOwner('realtime-owner-header@wnd.test');

        $provider = ProviderAccount::query()->create([
            'tenant_id' => $owner['tenant_id'],
            'provider_type' => 'twilio',
            'display_name' => 'Realtime Provider Header',
            'credentials_encrypted' => [
                'account_sid' => 'ACHEADER',
                'auth_token' => 'token-header',
            ],
            'status' => 'active',
        ]);

        $call = CallSession::query()->create([
            'tenant_id' => $owner['tenant_id'],
            'provider_account_id' => $provider->id,
            'direction' => 'outbound',
            'status' => 'in_progress',
            'provider_call_id' => 'CAHEADER001',
            'from_number' => '+15005550009',
            'to_number' => '+14155554000',
        ]);

        $firstTimestamp = now()->subSeconds(5);
        $first = CallEvent::query()->create([
            'tenant_id' => $owner['tenant_id'],
            'call_session_id' => $call->id,
            'provider_account_id' => $provider->id,
            'event_type' => 'call.answered',
            'provider_event_type' => 'answered',
            'status_after' => 'in_progress',
            'payload' => ['step' => 1],
            'occurred_at' => $firstTimestamp,
        ]);
        $first->forceFill([
            'created_at' => $firstTimestamp,
            'updated_at' => $firstTimestamp,
        ])->saveQuietly();

        $secondTimestamp = $firstTimestamp->copy()->addSeconds(3);
        $second = CallEvent::query()->create([
            'tenant_id' => $owner['tenant_id'],
            'call_session_id' => $call->id,
            'provider_account_id' => $provider->id,
            'event_type' => 'call.completed',
            'provider_event_type' => 'completed',
            'status_after' => 'completed',
            'payload' => ['step' => 2],
            'occurred_at' => $secondTimestamp,
        ]);
        $second->forceFill([
            'created_at' => $secondTimestamp,
            'updated_at' => $secondTimestamp,
        ])->saveQuietly();

        $first->refresh();
        $second->refresh();
        $cursor = $first->created_at?->format('Y-m-d\TH:i:s.u\Z').'|'.$first->id;

        $response = $this->call(
            'GET',
            '/api/v1/realtime/calls/stream',
            [],
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer '.$owner['token'],
                'HTTP_X_TENANT_ID' => $owner['tenant_id'],
                'HTTP_LAST_EVENT_ID' => (string) $cursor,
            ]
        );

        $response->assertOk();
        $stream = $response->streamedContent();

        $this->assertStringContainsString((string) $second->id, $stream);
        $this->assertStringNotContainsString((string) $first->id, $stream);
    }

    /**
     * @return array{token:string, tenant_id:string}
     */
    private function registerOwner(string $email): array
    {
        $register = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Realtime Tenant',
            'first_name' => 'Realtime',
            'last_name' => 'Owner',
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'timezone' => 'UTC',
        ])->assertCreated();

        return [
            'token' => (string) $register->json('data.token'),
            'tenant_id' => (string) $register->json('data.tenant.id'),
        ];
    }
}
