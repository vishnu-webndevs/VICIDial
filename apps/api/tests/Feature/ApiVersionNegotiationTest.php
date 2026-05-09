<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiVersionNegotiationTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_to_v1_when_accept_header_is_absent(): void
    {
        $this->getJson('/api/v1/plans')
            ->assertOk()
            ->assertHeader('X-API-Version', 'v1');
    }

    public function test_resolves_v2_when_vendor_accept_header_is_v2(): void
    {
        $this->get('/api/v1/plans', [
            'Accept' => 'application/vnd.wnddialer.v2+json',
        ])
            ->assertOk()
            ->assertHeader('X-API-Version', 'v2');
    }

    public function test_rejects_unsupported_vendor_version(): void
    {
        $this->get('/api/v1/plans', [
            'Accept' => 'application/vnd.wnddialer.v3+json',
        ])
            ->assertStatus(406)
            ->assertJsonPath('error.code', 'API_VERSION_NOT_ACCEPTABLE');
    }
}
