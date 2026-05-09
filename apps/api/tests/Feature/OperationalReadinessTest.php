<?php

namespace Tests\Feature;

use Tests\TestCase;

class OperationalReadinessTest extends TestCase
{
    public function test_liveness_endpoint_is_available_and_sets_operational_headers(): void
    {
        $response = $this->getJson('/api/health/live');

        $response
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('check', 'liveness')
            ->assertHeader('X-Request-Id')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_readiness_endpoint_reports_status_and_dependencies(): void
    {
        $response = $this->getJson('/api/health/ready');

        $response
            ->assertOk()
            ->assertJsonPath('check', 'readiness')
            ->assertJsonStructure([
                'status',
                'service',
                'check',
                'dependencies' => ['database', 'cache'],
                'timestamp',
            ]);
    }

    public function test_unauthenticated_api_error_is_standardized_and_contains_request_id(): void
    {
        $response = $this->getJson('/api/v1/auth/me');

        $response
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED')
            ->assertJsonPath('error.message', 'Unauthenticated.')
            ->assertJsonStructure([
                'success',
                'error' => ['code', 'message'],
                'meta' => ['request_id'],
            ]);
    }

    public function test_auth_endpoints_are_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'ops-rate-limit-test@example.com',
                'password' => 'invalid-password',
            ]);
        }

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'ops-rate-limit-test@example.com',
            'password' => 'invalid-password',
        ]);

        $response->assertStatus(429);
    }
}
