<?php

namespace Tests\Feature;

use App\Models\MessageThread;
use Database\Seeders\PlanCatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class Part3AlignmentFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(PlanCatalogSeeder::class);
    }

    public function test_phase_one_endpoints_support_contact_project_context_and_voice_runtime(): void
    {
        [$tenantId, $token, $userId] = $this->registerOwner('phase1-owner@wnd.test');

        $contact = $this->postJson('/api/v1/contacts', [
            'display_name' => 'John Caller',
            'company' => 'Acme',
            'phones' => [
                ['e164' => '+15551230001', 'is_primary' => true],
            ],
        ], $this->headers($token, $tenantId))
            ->assertCreated()
            ->json('data.id');

        $project = $this->postJson('/api/v1/projects', [
            'name' => 'HVAC Retrofit',
            'status' => 'active',
            'priority' => 'high',
        ], $this->headers($token, $tenantId))
            ->assertCreated()
            ->json('data.id');

        $this->postJson("/api/v1/projects/{$project}/contacts", [
            'contact_id' => $contact,
            'relationship_type' => 'owner',
            'is_primary' => true,
        ], $this->headers($token, $tenantId))->assertCreated();

        $this->postJson("/api/v1/projects/{$project}/assignments", [
            'engineer_id' => $userId,
            'role' => 'primary',
        ], $this->headers($token, $tenantId))->assertCreated();

        $this->getJson('/api/v1/interaction-context?phone=%2B15551230001', $this->headers($token, $tenantId))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.contact.id', $contact);

        $this->postJson('/api/v1/ring-groups', [
            'name' => 'Default Ring Group',
            'strategy' => 'simultaneous',
            'members' => [
                ['target_type' => 'user', 'target_id' => $userId, 'priority' => 10],
            ],
        ], $this->headers($token, $tenantId))->assertCreated();

        $this->postJson('/api/v1/extensions', [
            'extension' => '1001',
            'target_type' => 'user',
            'target_id' => $userId,
        ], $this->headers($token, $tenantId))->assertCreated();

        $this->postJson('/api/v1/voicemail', [
            'contact_id' => $contact,
            'project_id' => $project,
            'from_number' => '+15551230001',
            'to_number' => '+15550009999',
            'transcript' => 'Please call me back.',
            'status' => 'captured',
        ], $this->headers($token, $tenantId))
            ->assertCreated()
            ->assertJsonPath('success', true);
    }

    public function test_phase_two_and_three_mock_integrations_are_available(): void
    {
        [$tenantId, $token] = $this->registerOwner('phase23-owner@wnd.test');

        $this->postJson('/api/webhooks/sms/mock', [
            'tenant_id' => $tenantId,
            'from' => '+15551230002',
            'to' => '+15550001111',
            'body' => 'Inbound SMS test',
        ])->assertCreated();

        $this->postJson('/api/webhooks/whatsapp/mock', [
            'tenant_id' => $tenantId,
            'from' => '+15551230003',
            'to' => '+15550001111',
            'body' => 'Inbound WhatsApp test',
        ])->assertCreated();
        $waThreadId = MessageThread::query()
            ->where('tenant_id', $tenantId)
            ->where('channel', 'whatsapp')
            ->where('counterparty_number', '+15551230003')
            ->value('id');
        $this->assertNotNull($waThreadId);

        $this->postJson('/api/v1/inbox/whatsapp-opt-in', [
            'tenant_id' => $tenantId,
            'counterparty_number' => '+15551230003',
            'opted_in' => false,
            'source' => 'compliance_center',
        ], $this->headers($token, $tenantId))->assertOk();

        $this->postJson("/api/v1/inbox/threads/{$waThreadId}/messages", [
            'body' => 'Outbound WhatsApp attempt while opted out',
        ], $this->headers($token, $tenantId))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'WHATSAPP_OPT_IN_REQUIRED');

        $this->postJson('/api/v1/ai/reception/handle', [
            'tenant_id' => $tenantId,
            'caller_number' => '+15551230004',
            'transcript' => 'Need service tomorrow morning.',
            'confidence_threshold' => 0.86,
        ], $this->headers($token, $tenantId))
            ->assertStatus(202)
            ->assertJsonPath('data.mode', 'sandbox');

        $this->postJson('/api/v1/integrations/graph/availability', [
            'tenant_id' => $tenantId,
            'duration_minutes' => 30,
            'from' => now()->toDateString(),
            'to' => now()->addDays(5)->toDateString(),
        ], $this->headers($token, $tenantId))->assertOk();

        $this->postJson('/api/v1/integrations/graph/book', [
            'tenant_id' => $tenantId,
            'start' => now()->addDay()->setTime(10, 0)->toDateTimeString(),
            'end' => now()->addDay()->setTime(10, 30)->toDateTimeString(),
            'attendee_email' => 'client@example.com',
            'subject' => 'Site Visit',
        ], $this->headers($token, $tenantId))
            ->assertCreated()
            ->assertJsonPath('data.confirmation_sent', true);

        $this->postJson('/api/v1/automation/workflows', [
            'tenant_id' => $tenantId,
            'workflow_key' => 'post-call-followup',
            'name' => 'Post Call Follow-up',
            'trigger_type' => 'manual',
            'steps' => [
                ['kind' => 'send_sms', 'template' => 'followup-template'],
            ],
        ], $this->headers($token, $tenantId))
            ->assertCreated()
            ->assertJsonPath('data.workflow_key', 'post-call-followup');
        $this->getJson('/api/v1/automation/workflows', $this->headers($token, $tenantId))
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1);

        $this->postJson('/api/v1/automation/workflows/run', [
            'tenant_id' => $tenantId,
            'workflow_key' => 'post-call-followup',
            'input' => ['lead_id' => 'lead-1'],
        ], $this->headers($token, $tenantId))
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'completed');

        $this->getJson("/api/v1/reporting/unified?tenant_id={$tenantId}", $this->headers($token, $tenantId))
            ->assertOk()
            ->assertJsonPath('data.mode', 'computed');

        $this->postJson('/api/v1/governance/retention-policy', [
            'tenant_id' => $tenantId,
            'retention_days' => 365,
            'pii_redaction_enabled' => true,
        ], $this->headers($token, $tenantId))
            ->assertStatus(202);
        $this->getJson('/api/v1/governance/retention-policy', $this->headers($token, $tenantId))
            ->assertOk()
            ->assertJsonPath('data.retention_days', 365);

        $this->postJson('/api/v1/governance/drill', [
            'tenant_id' => $tenantId,
            'scenario' => 'db_restore',
        ], $this->headers($token, $tenantId))
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'completed');
        $this->getJson('/api/v1/governance/drills', $this->headers($token, $tenantId))
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1);

        $this->getJson('/api/v1/features/planned/status', $this->headers($token, $tenantId))
            ->assertOk()
            ->assertJsonCount(6, 'data.features');
    }

    public function test_phase_two_and_three_integrations_switch_to_live_adapter_when_enabled(): void
    {
        [$tenantId, $token] = $this->registerOwner('phase23-live-owner@wnd.test');
        $this->setProductionIntegrationMode();

        config()->set('services.part3', [
            'enabled' => true,
            'auth_token' => 'test-token',
            'ai' => [
                'enabled' => true,
                'url' => 'https://provider.example.test/ai/handle',
            ],
            'graph' => [
                'enabled' => true,
                'availability_url' => 'https://provider.example.test/graph/availability',
                'booking_url' => 'https://provider.example.test/graph/book',
            ],
            'workflow' => [
                'enabled' => true,
                'url' => 'https://provider.example.test/workflow/run',
            ],
            'reporting' => [
                'enabled' => true,
                'url' => 'https://provider.example.test/reporting/unified',
            ],
            'governance' => [
                'enabled' => true,
                'retention_url' => 'https://provider.example.test/governance/retention',
                'drill_url' => 'https://provider.example.test/governance/drill',
            ],
        ]);

        Http::fake([
            'https://provider.example.test/ai/handle' => Http::response([
                'decision' => 'auto_route',
                'confidence' => 0.93,
                'recommended_route' => 'ring_group:priority',
                'processed_at' => now()->toISOString(),
            ], 200),
            'https://provider.example.test/graph/availability' => Http::response([
                'slots' => [
                    ['start' => now()->addDay()->setTime(9, 0)->toISOString(), 'end' => now()->addDay()->setTime(9, 30)->toISOString()],
                ],
            ], 200),
            'https://provider.example.test/graph/book' => Http::response([
                'booking_id' => 'live_booking_1',
                'calendar_event_id' => 'evt_live_1',
                'confirmation_sent' => true,
            ], 200),
            'https://provider.example.test/workflow/run' => Http::response([
                'run_id' => 'wf_live_1',
                'status' => 'completed',
                'output' => ['steps_executed' => 4],
            ], 200),
            'https://provider.example.test/reporting/unified' => Http::response([
                'kpis' => ['voice_calls' => 10],
                'ai' => ['average_confidence' => 0.9],
            ], 200),
            'https://provider.example.test/governance/retention' => Http::response([
                'policy_id' => 'ret_live_1',
                'effective_at' => now()->toISOString(),
            ], 200),
            'https://provider.example.test/governance/drill' => Http::response([
                'drill_id' => 'drill_live_1',
                'status' => 'completed',
                'rto_minutes' => 12,
                'rpo_minutes' => 4,
            ], 200),
        ]);

        $this->postJson('/api/v1/ai/reception/handle', [
            'tenant_id' => $tenantId,
            'caller_number' => '+15551230005',
            'transcript' => 'Need service this afternoon.',
            'confidence_threshold' => 0.8,
        ], $this->headers($token, $tenantId))
            ->assertStatus(202)
            ->assertJsonPath('data.mode', 'live')
            ->assertJsonPath('data.decision', 'auto_route');

        $this->postJson('/api/v1/integrations/graph/availability', [
            'tenant_id' => $tenantId,
            'duration_minutes' => 30,
            'from' => now()->toDateString(),
            'to' => now()->addDays(2)->toDateString(),
        ], $this->headers($token, $tenantId))
            ->assertOk()
            ->assertJsonPath('data.mode', 'live');

        $this->postJson('/api/v1/integrations/graph/book', [
            'tenant_id' => $tenantId,
            'start' => now()->addDay()->setTime(9, 0)->toDateTimeString(),
            'end' => now()->addDay()->setTime(9, 30)->toDateTimeString(),
            'attendee_email' => 'client@example.com',
            'subject' => 'Live Booking',
        ], $this->headers($token, $tenantId))
            ->assertCreated()
            ->assertJsonPath('data.mode', 'live')
            ->assertJsonPath('data.booking_id', 'live_booking_1');

        $this->postJson('/api/v1/automation/workflows/run', [
            'tenant_id' => $tenantId,
            'workflow_key' => 'live-workflow',
            'input' => ['case_id' => 'case-1'],
        ], $this->headers($token, $tenantId))
            ->assertStatus(202)
            ->assertJsonPath('data.mode', 'live')
            ->assertJsonPath('data.run_id', 'wf_live_1');

        $this->getJson("/api/v1/reporting/unified?tenant_id={$tenantId}", $this->headers($token, $tenantId))
            ->assertOk()
            ->assertJsonPath('data.mode', 'live')
            ->assertJsonPath('data.kpis.voice_calls', 10);

        $this->postJson('/api/v1/governance/retention-policy', [
            'tenant_id' => $tenantId,
            'retention_days' => 180,
            'pii_redaction_enabled' => true,
        ], $this->headers($token, $tenantId))
            ->assertStatus(202)
            ->assertJsonPath('data.mode', 'live')
            ->assertJsonPath('data.policy_id', 'ret_live_1');

        $this->postJson('/api/v1/governance/drill', [
            'tenant_id' => $tenantId,
            'scenario' => 'provider_outage',
        ], $this->headers($token, $tenantId))
            ->assertStatus(202)
            ->assertJsonPath('data.mode', 'live')
            ->assertJsonPath('data.drill_id', 'drill_live_1');

        Http::assertSentCount(7);
    }

    public function test_live_adapter_falls_back_to_mock_when_provider_errors_and_sends_auth_token(): void
    {
        [$tenantId, $token] = $this->registerOwner('phase23-live-fallback@wnd.test');
        $this->setProductionIntegrationMode();

        config()->set('services.part3', [
            'enabled' => true,
            'auth_token' => 'test-token',
            'ai' => [
                'enabled' => true,
                'url' => 'https://provider.example.test/ai/handle',
            ],
        ]);

        Http::fake([
            'https://provider.example.test/ai/handle' => Http::response([
                'message' => 'provider unavailable',
            ], 500),
        ]);

        $this->postJson('/api/v1/ai/reception/handle', [
            'tenant_id' => $tenantId,
            'caller_number' => '+15551230111',
            'transcript' => 'Need immediate assistance.',
            'confidence_threshold' => 0.85,
        ], $this->headers($token, $tenantId))
            ->assertStatus(202)
            ->assertJsonPath('data.mode', 'mock')
            ->assertJsonPath('data.decision', 'auto_route');

        Http::assertSent(function (HttpRequest $request): bool {
            return $request->url() === 'https://provider.example.test/ai/handle'
                && $request->hasHeader('Authorization', 'Bearer test-token');
        });
        Http::assertSentCount(1);
    }

    public function test_live_adapter_respects_scope_toggle_and_avoids_http_when_scope_disabled(): void
    {
        [$tenantId, $token] = $this->registerOwner('phase23-live-scope-off@wnd.test');
        $this->setProductionIntegrationMode();

        config()->set('services.part3', [
            'enabled' => true,
            'auth_token' => 'test-token',
            'reporting' => [
                'enabled' => false,
                'url' => 'https://provider.example.test/reporting/unified',
            ],
        ]);

        Http::fake();

        $this->getJson("/api/v1/reporting/unified?tenant_id={$tenantId}", $this->headers($token, $tenantId))
            ->assertOk()
            ->assertJsonPath('data.mode', 'mock');

        Http::assertNothingSent();
    }

    public function test_threads_send_message_routes_sms_and_whatsapp_to_live_provider_adapter(): void
    {
        [$tenantId, $token] = $this->registerOwner('phase23-live-messaging@wnd.test');
        $this->setProductionIntegrationMode();

        config()->set('services.part3', [
            'enabled' => true,
            'auth_token' => 'test-token',
            'messaging' => [
                'sms' => [
                    'enabled' => true,
                    'outbound_url' => 'https://provider.example.test/messaging/sms/outbound',
                ],
                'whatsapp' => [
                    'enabled' => true,
                    'outbound_url' => 'https://provider.example.test/messaging/whatsapp/outbound',
                ],
            ],
        ]);

        Http::fake([
            'https://provider.example.test/messaging/sms/outbound' => Http::response([
                'provider_message_id' => 'sms_live_1',
                'status' => 'sent_live',
                'sent_at' => now()->toISOString(),
                'delivered_at' => now()->toISOString(),
            ], 200),
            'https://provider.example.test/messaging/whatsapp/outbound' => Http::response([
                'provider_message_id' => 'wa_live_1',
                'status' => 'sent_live',
                'sent_at' => now()->toISOString(),
                'delivered_at' => now()->toISOString(),
            ], 200),
        ]);

        $this->postJson('/api/webhooks/sms/mock', [
            'tenant_id' => $tenantId,
            'from' => '+15551239991',
            'to' => '+15550001111',
            'body' => 'Inbound SMS seed',
        ])->assertCreated();

        $this->postJson('/api/webhooks/whatsapp/mock', [
            'tenant_id' => $tenantId,
            'from' => '+15551239992',
            'to' => '+15550001111',
            'body' => 'Inbound WhatsApp seed',
        ])->assertCreated();

        $smsThreadId = MessageThread::query()
            ->where('tenant_id', $tenantId)
            ->where('channel', 'sms')
            ->where('counterparty_number', '+15551239991')
            ->value('id');
        $waThreadId = MessageThread::query()
            ->where('tenant_id', $tenantId)
            ->where('channel', 'whatsapp')
            ->where('counterparty_number', '+15551239992')
            ->value('id');

        $this->assertNotNull($smsThreadId);
        $this->assertNotNull($waThreadId);

        $this->postJson("/api/v1/inbox/threads/{$smsThreadId}/messages", [
            'body' => 'Outbound SMS live',
        ], $this->headers($token, $tenantId))
            ->assertStatus(202)
            ->assertJsonPath('data.provider_message_id', 'sms_live_1')
            ->assertJsonPath('data.status', 'sent_live')
            ->assertJsonPath('data.metadata.mode', 'live')
            ->assertJsonPath('data.metadata.channel', 'sms');

        $this->postJson("/api/v1/inbox/threads/{$waThreadId}/messages", [
            'body' => 'Outbound WhatsApp live',
        ], $this->headers($token, $tenantId))
            ->assertStatus(202)
            ->assertJsonPath('data.provider_message_id', 'wa_live_1')
            ->assertJsonPath('data.status', 'sent_live')
            ->assertJsonPath('data.metadata.mode', 'live')
            ->assertJsonPath('data.metadata.channel', 'whatsapp');

        Http::assertSent(function (HttpRequest $request): bool {
            return $request->url() === 'https://provider.example.test/messaging/sms/outbound'
                && $request->hasHeader('Authorization', 'Bearer test-token')
                && ($request['body'] ?? null) === 'Outbound SMS live';
        });
        Http::assertSent(function (HttpRequest $request): bool {
            return $request->url() === 'https://provider.example.test/messaging/whatsapp/outbound'
                && $request->hasHeader('Authorization', 'Bearer test-token')
                && ($request['body'] ?? null) === 'Outbound WhatsApp live';
        });
        Http::assertSentCount(2);
    }

    private function registerOwner(string $email): array
    {
        $register = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Part3 Tenant',
            'first_name' => 'Part3',
            'last_name' => 'Owner',
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'timezone' => 'UTC',
        ])->assertCreated();

        return [
            $register->json('data.tenant.id'),
            $register->json('data.token'),
            $register->json('data.user.id'),
        ];
    }

    private function headers(string $token, string $tenantId): array
    {
        return [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-Id' => $tenantId,
        ];
    }

    private function setProductionIntegrationMode(): void
    {
        config()->set('integrations.mode', 'production');
    }
}
