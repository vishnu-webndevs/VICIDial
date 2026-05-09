<?php

namespace Tests\Feature;

use App\Models\Plan;
use Database\Seeders\PlanCatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingStripeFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(PlanCatalogSeeder::class);
    }

    public function test_change_plan_updates_subscription_without_stripe_dependencies(): void
    {
        $starter = Plan::query()->where('slug', 'starter')->firstOrFail();
        $growth = Plan::query()->where('slug', 'growth')->firstOrFail();

        $register = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Plan Tenant',
            'first_name' => 'Plan',
            'last_name' => 'Owner',
            'email' => 'plan-owner@wnd.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'timezone' => 'UTC',
        ])->assertCreated();

        $tenantId = $register->json('data.tenant.id');
        $token = $register->json('data.token');

        $this->postJson('/api/v1/subscription/change-plan', [
            'plan_slug' => 'growth',
            'billing_cycle' => 'monthly',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-Id' => $tenantId,
        ])->assertOk()
            ->assertJsonPath('data.plan.slug', 'growth');

        $this->assertDatabaseHas('subscriptions', [
            'tenant_id' => $tenantId,
            'plan_id' => $growth->id,
            'status' => 'active',
        ]);

        $this->assertDatabaseMissing('subscriptions', [
            'tenant_id' => $tenantId,
            'plan_id' => $starter->id,
            'status' => 'active',
        ]);
    }
}
