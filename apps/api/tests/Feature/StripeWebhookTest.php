<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StripeWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_stripe_webhook_endpoint_is_disabled(): void
    {
        $this->postJson('/api/webhooks/stripe', [])->assertNotFound();
    }
}
