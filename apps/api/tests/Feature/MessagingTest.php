<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Message;
use App\Models\ProviderAccount;
use Database\Seeders\PlanCatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessagingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(PlanCatalogSeeder::class);
    }

    public function test_sms_send_and_receive_flow(): void
    {
        $register = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Messaging Tenant',
            'first_name' => 'Messaging',
            'last_name' => 'Owner',
            'email' => 'messaging-owner@wnd.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'timezone' => 'UTC',
        ])->assertCreated();

        $tenantId = $register->json('data.tenant.id');
        $token = $register->json('data.token');

        $lead = Lead::query()->create([
            'tenant_id' => $tenantId,
            'full_name' => 'SMS Lead',
            'phone' => '+15555550101',
            'email' => 'sms-lead@wnd.test',
            'status' => 'new',
        ]);

        ProviderAccount::query()->create([
            'tenant_id' => $tenantId,
            'provider_type' => 'twilio',
            'display_name' => 'Twilio',
            'credentials_encrypted' => [
                'account_sid' => 'ACsms123',
                'auth_token' => 'token',
            ],
            'status' => 'active',
        ]);

        $this->postJson("/api/v1/leads/{$lead->id}/sms", [
            'content' => 'Hello from agent',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-Id' => $tenantId,
        ])->dump()->assertCreated()
            ->assertJsonPath('data.direction', 'outbound');

        $this->postJson('/api/v1/webhooks/twilio/sms', [
            'AccountSid' => 'ACsms123',
            'From' => '+15555550101',
            'To' => '+15005550006',
            'Body' => 'Inbound SMS',
            'MessageSid' => 'SM123',
        ])->assertOk();

        $this->assertDatabaseHas('messages', [
            'tenant_id' => $tenantId,
            'direction' => 'inbound',
            'provider_message_id' => 'SM123',
            'body' => 'Inbound SMS',
        ]);
    }

    public function test_whatsapp_send_and_receive_flow(): void
    {
        $register = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'WhatsApp Tenant',
            'first_name' => 'Whats',
            'last_name' => 'Owner',
            'email' => 'wa-owner@wnd.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'timezone' => 'UTC',
        ])->assertCreated();

        $tenantId = $register->json('data.tenant.id');
        $token = $register->json('data.token');

        $lead = Lead::query()->create([
            'tenant_id' => $tenantId,
            'full_name' => 'WA Lead',
            'phone' => '+15555550102',
            'email' => 'wa-lead@wnd.test',
            'status' => 'new',
        ]);

        ProviderAccount::query()->create([
            'tenant_id' => $tenantId,
            'provider_type' => 'twilio',
            'display_name' => 'Twilio',
            'credentials_encrypted' => [
                'account_sid' => 'ACwa123',
                'auth_token' => 'token',
                'whatsapp_from' => 'whatsapp:+15005550006',
            ],
            'status' => 'active',
        ]);

        $this->postJson("/api/v1/leads/{$lead->id}/whatsapp", [
            'content' => 'Hello via WA',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-Id' => $tenantId,
        ])->assertCreated()
            ->assertJsonPath('data.direction', 'outbound');

        $this->postJson('/api/v1/webhooks/twilio/whatsapp', [
            'AccountSid' => 'ACwa123',
            'From' => 'whatsapp:+15555550102',
            'To' => 'whatsapp:+15005550006',
            'Body' => 'Inbound WhatsApp',
            'MessageSid' => 'WA123',
        ])->assertOk();

        /** @var Message $inbound */
        $inbound = Message::query()
            ->where('tenant_id', $tenantId)
            ->where('provider_message_id', 'WA123')
            ->firstOrFail();
        $this->assertSame('inbound', $inbound->direction);
        $this->assertSame('Inbound WhatsApp', $inbound->body);
    }
}
