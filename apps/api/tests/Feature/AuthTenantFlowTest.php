<?php

namespace Tests\Feature;

use App\Models\UsageMeter;
use App\Models\UsageEvent;
use App\Models\User;
use App\Models\LeadList;
use Database\Seeders\PlanCatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Auth\Notifications\ResetPassword;
use Tests\TestCase;

class AuthTenantFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(PlanCatalogSeeder::class);
    }

    public function test_user_can_register_login_and_fetch_tenant_context(): void
    {
        $register = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'WND Labs',
            'first_name' => 'Owner',
            'last_name' => 'User',
            'email' => 'owner@wnd.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'timezone' => 'UTC',
        ]);

        $register->assertCreated()
            ->assertJsonPath('data.user.email', 'owner@wnd.test')
            ->assertJsonPath('data.tenant.name', 'WND Labs');

        $tenantId = $register->json('data.tenant.id');
        $token = $register->json('data.token');

        $this->assertDatabaseHas('lead_lists', [
            'tenant_id' => $tenantId,
            'name' => 'Default List',
            'is_active' => 1,
        ]);
        $this->assertSame(1, LeadList::query()->where('tenant_id', $tenantId)->count());

        $this->getJson('/api/v1/auth/me', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-Id' => $tenantId,
        ])->assertOk()
            ->assertJsonPath('data.current_tenant.id', $tenantId)
            ->assertJsonPath('data.role.slug', 'company_owner');

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'owner@wnd.test',
            'password' => 'password123',
        ]);

        $login->assertOk()
            ->assertJsonPath('data.user.email', 'owner@wnd.test')
            ->assertJsonStructure(['data' => ['token', 'user']]);
    }

    public function test_owner_can_invite_and_user_can_accept_invitation(): void
    {
        $register = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'WND Labs',
            'first_name' => 'Owner',
            'last_name' => 'User',
            'email' => 'owner2@wnd.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'timezone' => 'UTC',
        ])->assertCreated();

        $tenantId = $register->json('data.tenant.id');
        $ownerToken = $register->json('data.token');

        $invite = $this->postJson('/api/v1/team/invitations', [
            'email' => 'agent@wnd.test',
            'role' => 'support_analyst',
        ], [
            'Authorization' => "Bearer {$ownerToken}",
            'X-Tenant-Id' => $tenantId,
        ])->assertCreated();

        $invitationToken = $invite->json('data.invitation_token');
        $this->assertNotEmpty($invitationToken);

        $this->postJson("/api/v1/team/invitations/{$invitationToken}/accept", [
            'email' => 'agent@wnd.test',
            'first_name' => 'Support',
            'last_name' => 'Agent',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertOk()
            ->assertJsonPath('data.user.email', 'agent@wnd.test')
            ->assertJsonPath('data.membership.role', 'support_analyst');
    }

    public function test_user_can_reset_password_and_login_with_new_password(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/register', [
            'company_name' => 'WND Labs',
            'first_name' => 'Reset',
            'last_name' => 'User',
            'email' => 'reset@wnd.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'timezone' => 'UTC',
        ])->assertCreated();

        $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'reset@wnd.test',
        ])->assertOk();

        $user = User::query()->where('email', 'reset@wnd.test')->firstOrFail();
        $token = null;

        Notification::assertSentTo(
            [$user],
            ResetPassword::class,
            function (ResetPassword $notification) use (&$token) {
                $token = $notification->token;

                return true;
            }
        );

        $this->assertNotNull($token);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => 'reset@wnd.test',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'reset@wnd.test',
            'password' => 'newpassword123',
        ])->assertOk()
            ->assertJsonPath('data.user.email', 'reset@wnd.test');
    }

    public function test_owner_can_view_tenant_scoped_audit_logs(): void
    {
        $register = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'WND Labs',
            'first_name' => 'Owner',
            'last_name' => 'User',
            'email' => 'audit@wnd.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'timezone' => 'UTC',
        ])->assertCreated();

        $tenantId = $register->json('data.tenant.id');
        $token = $register->json('data.token');

        $this->getJson('/api/v1/audit-logs', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-Id' => $tenantId,
        ])->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['pagination']]);
    }

    public function test_owner_can_access_plan_subscription_endpoints(): void
    {
        $register = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'WND Labs',
            'first_name' => 'Billing',
            'last_name' => 'Owner',
            'email' => 'billing-owner@wnd.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'timezone' => 'UTC',
        ])->assertCreated();

        $tenantId = $register->json('data.tenant.id');
        $token = $register->json('data.token');

        $this->getJson('/api/v1/plans')->assertOk();

        $this->getJson('/api/v1/subscription', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-Id' => $tenantId,
        ])->assertOk();

        $this->postJson('/api/v1/subscription/change-plan', [
            'plan_slug' => 'starter',
            'billing_cycle' => 'monthly',
        ], [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-Id' => $tenantId,
        ])->assertOk()
            ->assertJsonPath('data.plan.slug', 'starter');
    }

    public function test_request_is_blocked_when_api_request_quota_is_exhausted(): void
    {
        $register = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Quota Labs',
            'first_name' => 'Quota',
            'last_name' => 'Owner',
            'email' => 'quota-owner@wnd.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'timezone' => 'UTC',
        ])->assertCreated();

        $tenantId = $register->json('data.tenant.id');
        $token = $register->json('data.token');

        $meter = UsageMeter::query()
            ->where('tenant_id', $tenantId)
            ->where('meter_type', 'api_requests')
            ->firstOrFail();
        $meter->update([
            'limit_units' => 1,
            'consumed_units' => 1,
        ]);

        $this->getJson('/api/v1/auth/me', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-Id' => $tenantId,
        ])->assertStatus(429)
            ->assertJsonPath('error.code', 'BILLING_USAGE_LIMIT_EXCEEDED')
            ->assertHeader('X-RateLimit-Limit', '1')
            ->assertHeader('X-RateLimit-Remaining', '0');
    }

    public function test_quota_resets_after_period_end_and_allows_request(): void
    {
        $register = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Reset Quota Labs',
            'first_name' => 'Reset',
            'last_name' => 'Owner',
            'email' => 'reset-quota-owner@wnd.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'timezone' => 'UTC',
        ])->assertCreated();

        $tenantId = $register->json('data.tenant.id');
        $token = $register->json('data.token');

        $meter = UsageMeter::query()
            ->where('tenant_id', $tenantId)
            ->where('meter_type', 'api_requests')
            ->firstOrFail();
        $meter->update([
            'limit_units' => 1,
            'consumed_units' => 1,
            'period_start' => now()->subMonths(2)->startOfDay(),
            'period_end' => now()->subDay()->endOfDay(),
        ]);

        $this->getJson('/api/v1/auth/me', [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-Id' => $tenantId,
        ])->assertOk()
            ->assertHeader('X-RateLimit-Limit', '1')
            ->assertHeader('X-RateLimit-Remaining', '0');

        $meter->refresh();
        $this->assertSame(1, (int) $meter->consumed_units);
        $this->assertTrue($meter->period_end->isFuture());

        $this->assertDatabaseHas('usage_events', [
            'tenant_id' => $tenantId,
            'meter_type' => 'api_requests',
            'source_type' => 'api_request',
        ]);
        $this->assertSame(1, UsageEvent::query()->where('tenant_id', $tenantId)->count());
    }
}
