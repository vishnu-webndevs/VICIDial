<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignRun;
use App\Models\Lead;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Campaigns\CampaignRunnerService;
use Database\Seeders\PlanCatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignRunnerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(PlanCatalogSeeder::class);
    }

    public function test_auto_pause_when_no_agents_are_online(): void
    {
        [$tenant, $user] = $this->createTenantAndUser();
        $campaign = Campaign::query()->create([
            'tenant_id' => $tenant->id,
            'created_by' => $user->id,
            'name' => 'No Agent Campaign',
            'status' => 'running',
            'queue_size' => 10,
            'calls_per_minute' => 5,
            'auto_pause_when_no_agents' => true,
        ]);

        $run = CampaignRun::query()->create([
            'tenant_id' => $tenant->id,
            'campaign_id' => $campaign->id,
            'started_by' => $user->id,
            'status' => 'running',
            'calls_per_minute' => 5,
            'started_at' => now(),
        ]);
        $lead = Lead::query()->create([
            'tenant_id' => $tenant->id,
            'full_name' => 'No Agent Lead',
            'phone' => '+15555550011',
            'status' => 'new',
        ]);
        \App\Models\DialQueueItem::query()->create([
            'tenant_id' => $tenant->id,
            'campaign_id' => $campaign->id,
            'campaign_run_id' => $run->id,
            'lead_id' => $lead->id,
            'priority' => 1,
            'attempt_count' => 0,
            'max_attempts' => 2,
            'status' => 'pending',
            'enqueued_at' => now(),
        ]);

        app(CampaignRunnerService::class)->tick($run);

        $run->refresh();
        $campaign->refresh();

        $this->assertSame('paused', $run->status);
        $this->assertSame('paused', $campaign->status);
        $this->assertSame('no_agents_mapped', $run->metadata['pause_reason'] ?? null);
    }

    public function test_window_enforcement_pauses_campaign_when_outside_allowed_hours(): void
    {
        [$tenant, $user] = $this->createTenantAndUser();
        $campaign = Campaign::query()->create([
            'tenant_id' => $tenant->id,
            'created_by' => $user->id,
            'name' => 'Window Campaign',
            'status' => 'running',
            'queue_size' => 10,
            'calls_per_minute' => 5,
            'auto_pause_when_no_agents' => false,
            'settings' => [
                'allowed_calling_hours' => [
                    'start' => now('UTC')->addMinutes(2)->format('H:i'),
                    'end' => now('UTC')->addMinutes(3)->format('H:i'),
                    'timezone' => 'UTC',
                ],
            ],
        ]);

        CampaignRun::query()->create([
            'tenant_id' => $tenant->id,
            'campaign_id' => $campaign->id,
            'started_by' => $user->id,
            'status' => 'running',
            'calls_per_minute' => 5,
            'started_at' => now(),
        ]);

        $lead = Lead::query()->create([
            'tenant_id' => $tenant->id,
            'full_name' => 'Window Lead',
            'phone' => '+15555550099',
            'status' => 'new',
            'notes' => ['timezone' => 'UTC'],
        ]);

        $run = CampaignRun::query()->where('campaign_id', $campaign->id)->firstOrFail();
        \App\Models\DialQueueItem::query()->create([
            'tenant_id' => $tenant->id,
            'campaign_id' => $campaign->id,
            'campaign_run_id' => $run->id,
            'lead_id' => $lead->id,
            'priority' => 1,
            'attempt_count' => 0,
            'max_attempts' => 2,
            'status' => 'pending',
            'enqueued_at' => now(),
        ]);

        app(CampaignRunnerService::class)->tick($run);

        $run->refresh();
        $campaign->refresh();

        $this->assertSame('paused', $run->status);
        $this->assertSame('paused', $campaign->status);
        $this->assertSame('outside_allowed_calling_window', $run->metadata['pause_reason'] ?? null);
    }

    public function test_completion_creates_notifications(): void
    {
        [$tenant, $user] = $this->createTenantAndUser();
        $campaign = Campaign::query()->create([
            'tenant_id' => $tenant->id,
            'created_by' => $user->id,
            'name' => 'Completion Campaign',
            'status' => 'running',
            'queue_size' => 10,
            'calls_per_minute' => 5,
            'auto_pause_when_no_agents' => false,
        ]);
        $run = CampaignRun::query()->create([
            'tenant_id' => $tenant->id,
            'campaign_id' => $campaign->id,
            'started_by' => $user->id,
            'status' => 'running',
            'calls_per_minute' => 5,
            'started_at' => now(),
        ]);

        app(CampaignRunnerService::class)->tick($run);

        $run->refresh();
        $campaign->refresh();

        $this->assertSame('completed', $run->status);
        $this->assertSame('completed', $campaign->status);
        $this->assertDatabaseHas('notifications', [
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'type' => 'campaign.completed',
        ]);
    }

    /**
     * @return array{Tenant, User}
     */
    private function createTenantAndUser(): array
    {
        $register = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Campaign Tenant',
            'first_name' => 'Campaign',
            'last_name' => 'Owner',
            'email' => 'campaign-owner-'.str()->lower(str()->random(6)).'@wnd.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'timezone' => 'UTC',
        ])->assertCreated();

        $tenant = Tenant::query()->findOrFail($register->json('data.tenant.id'));
        $user = User::query()->findOrFail($register->json('data.user.id'));

        Membership::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->update(['status' => 'active']);

        return [$tenant, $user];
    }
}
