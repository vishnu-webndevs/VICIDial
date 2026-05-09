<?php

namespace Tests\Unit;

use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Support\IntegrationMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntegrationModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolve_defaults_to_sandbox_in_local_auto_mode(): void
    {
        config([
            'integrations.mode' => 'auto',
            'app.env' => 'local',
        ]);

        $mode = new IntegrationMode;

        $this->assertSame('sandbox', $mode->resolve());
    }

    public function test_resolve_uses_tenant_metadata_override_when_available(): void
    {
        config([
            'integrations.mode' => 'sandbox',
            'app.env' => 'local',
        ]);

        $tenant = Tenant::query()->create([
            'name' => 'Mode Override Co',
            'slug' => 'mode-override-co',
            'status' => 'active',
        ]);

        TenantSetting::query()->create([
            'tenant_id' => $tenant->id,
            'metadata' => ['integration_mode' => 'production'],
        ]);

        request()->attributes->set('tenant', $tenant);

        $mode = new IntegrationMode;

        $this->assertSame('production', $mode->resolve());
    }

    public function test_resolve_ignores_invalid_tenant_metadata_override(): void
    {
        config([
            'integrations.mode' => 'sandbox',
            'app.env' => 'local',
        ]);

        $tenant = Tenant::query()->create([
            'name' => 'Invalid Mode Co',
            'slug' => 'invalid-mode-co',
            'status' => 'active',
        ]);

        TenantSetting::query()->create([
            'tenant_id' => $tenant->id,
            'metadata' => ['integration_mode' => 'qa'],
        ]);

        request()->attributes->set('tenant', $tenant);

        $mode = new IntegrationMode;

        $this->assertSame('sandbox', $mode->resolve());
    }
}
