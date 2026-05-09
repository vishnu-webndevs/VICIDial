<?php

namespace Tests\Feature;

use App\Models\CallSession;
use App\Models\ProviderAccount;
use Database\Seeders\PlanCatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CallRecordingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(PlanCatalogSeeder::class);
    }

    public function test_recording_retrieval_and_tagging(): void
    {
        $register = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Recording Tenant',
            'first_name' => 'Recording',
            'last_name' => 'Owner',
            'email' => 'recording-owner@wnd.test',
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
            'display_name' => 'Recorder',
            'credentials_encrypted' => ['account_sid' => 'ACrec', 'auth_token' => 'token'],
            'status' => 'active',
        ]);

        $call = CallSession::query()->create([
            'tenant_id' => $tenantId,
            'provider_account_id' => $provider->id,
            'initiated_by' => $userId,
            'direction' => 'outbound',
            'status' => 'completed',
            'provider_call_id' => 'call_rec_1',
            'from_number' => '+15555550001',
            'to_number' => '+15555550002',
            'duration_seconds' => 33,
            'recording_url' => 'https://example.test/recordings/call1.mp3',
            'recording_duration' => 30,
            'started_at' => now()->subMinute(),
            'ended_at' => now(),
        ]);

        $this->getJson("/api/v1/calls/{$call->id}/recording", [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-Id' => $tenantId,
        ])->assertOk()
            ->assertJsonPath('data.call_id', $call->id)
            ->assertJsonPath('data.recording_duration', 30);

        $this->postJson("/api/v1/calls/{$call->id}/tag", [
            'tags' => ['quality', 'follow_up'],
            'notes' => 'Customer asked for callback next week',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-Id' => $tenantId,
        ])->assertOk()
            ->assertJsonPath('data.call_id', $call->id);

        $this->assertDatabaseHas('call_sessions', [
            'id' => $call->id,
            'recording_notes' => 'Customer asked for callback next week',
        ]);
    }
}
