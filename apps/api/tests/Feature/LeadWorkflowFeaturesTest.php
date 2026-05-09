<?php

namespace Tests\Feature;

use Database\Seeders\PlanCatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadWorkflowFeaturesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(PlanCatalogSeeder::class);
    }

    public function test_lead_lists_dnc_and_dispositions_flow(): void
    {
        $register = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Lead Workflow Tenant',
            'first_name' => 'Lead',
            'last_name' => 'Owner',
            'email' => 'lead-owner@wnd.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'timezone' => 'UTC',
        ])->assertCreated();

        $token = $register->json('data.token');
        $tenantId = $register->json('data.tenant.id');

        $listId = $this->postJson('/api/v1/lead-lists', [
            'name' => 'Warm Leads',
            'description' => 'Interested prospects',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-Id' => $tenantId,
        ])->assertCreated()->json('data.id');

        $leadId = $this->postJson('/api/v1/leads', [
            'full_name' => 'Alex Prospect',
            'phone' => '+15555550100',
            'email' => 'alex@example.test',
            'status' => 'new',
            'list_ids' => [$listId],
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-Id' => $tenantId,
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/leads/dispositions', [
            'lead_id' => $leadId,
            'disposition' => 'callback',
            'notes' => 'Requested callback tomorrow',
            'callback_at' => now()->addDay()->toISOString(),
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-Id' => $tenantId,
        ])->assertCreated();

        $this->postJson('/api/v1/leads/dispositions', [
            'lead_id' => $leadId,
            'disposition' => 'dnc',
            'notes' => 'Do not call again',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-Id' => $tenantId,
        ])->assertCreated();

        $this->assertDatabaseHas('dnc_entries', [
            'tenant_id' => $tenantId,
            'phone' => '+15555550100',
        ]);
        $this->assertDatabaseHas('leads', [
            'id' => $leadId,
            'is_dnc' => true,
            'status' => 'dnc',
        ]);
        $this->assertDatabaseHas('lead_list_lead', [
            'lead_list_id' => $listId,
            'lead_id' => $leadId,
            'tenant_id' => $tenantId,
        ]);

        $this->getJson("/api/v1/leads/{$leadId}/timeline", [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-Id' => $tenantId,
        ])->assertOk()
            ->assertJsonPath('data.lead.id', $leadId);

        $this->getJson('/api/v1/leads/callbacks?state=due', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-Id' => $tenantId,
        ])->assertOk();

        $this->getJson('/api/v1/analytics/lists', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-Id' => $tenantId,
        ])->assertOk();
    }
}
