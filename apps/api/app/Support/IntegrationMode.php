<?php

namespace App\Support;

use App\Models\TenantSetting;
use RuntimeException;

class IntegrationMode
{
    public const SANDBOX = 'sandbox';
    public const PRODUCTION = 'production';

    public function resolve(): string
    {
        $configured = $this->resolveTenantIntegrationMode()
            ?? strtolower((string) config('integrations.mode', 'auto'));
        if (in_array($configured, [self::SANDBOX, self::PRODUCTION], true)) {
            return $configured;
        }

        if (app()->runningInConsole()) {
            $command = (string) ($_SERVER['argv'][1] ?? '');
            if ($command === 'serve') {
                return self::SANDBOX;
            }
        }

        return strtolower((string) config('app.env')) === self::PRODUCTION
            ? self::PRODUCTION
            : self::SANDBOX;
    }

    public function isSandbox(): bool
    {
        return $this->resolve() === self::SANDBOX;
    }

    public function isProduction(): bool
    {
        return $this->resolve() === self::PRODUCTION;
    }

    public function sandboxBaseUrl(): string
    {
        return rtrim((string) config('integrations.sandbox.base_url', ''), '/');
    }

    public function assertRuntimeSafety(): void
    {
        if ((bool) config('integrations.strict_validation', true) !== true) {
            return;
        }

        if ($this->isProduction()) {
            $this->assertProductionSafety();

            return;
        }

        $this->assertSandboxSafety();
    }

    private function assertProductionSafety(): void
    {
        $stripeSecret = (string) config('services.stripe.secret_key', '');
        if ($stripeSecret === '' || str_starts_with($stripeSecret, 'sk_test_')) {
            throw new RuntimeException('Production mode requires a live Stripe secret key.');
        }

        $part3Urls = [
            (string) data_get(config('services.part3', []), 'messaging.sms.inbound_url', ''),
            (string) data_get(config('services.part3', []), 'messaging.sms.outbound_url', ''),
            (string) data_get(config('services.part3', []), 'messaging.whatsapp.inbound_url', ''),
            (string) data_get(config('services.part3', []), 'messaging.whatsapp.outbound_url', ''),
            (string) data_get(config('services.part3', []), 'teams.url', ''),
            (string) data_get(config('services.part3', []), 'ai.url', ''),
            (string) data_get(config('services.part3', []), 'graph.availability_url', ''),
            (string) data_get(config('services.part3', []), 'graph.booking_url', ''),
            (string) data_get(config('services.part3', []), 'workflow.url', ''),
            (string) data_get(config('services.part3', []), 'reporting.url', ''),
            (string) data_get(config('services.part3', []), 'governance.retention_url', ''),
            (string) data_get(config('services.part3', []), 'governance.drill_url', ''),
        ];

        foreach ($part3Urls as $url) {
            if ($url !== '' && $this->looksLikeSandboxEndpoint($url)) {
                throw new RuntimeException('Production mode cannot point Part3 integrations to sandbox/mock URLs.');
            }
        }
    }

    private function assertSandboxSafety(): void
    {
        $stripeSecret = (string) config('services.stripe.secret_key', '');
        if (str_starts_with($stripeSecret, 'sk_live_')) {
            throw new RuntimeException('Sandbox mode cannot use a live Stripe secret key.');
        }
    }

    private function looksLikeSandboxEndpoint(string $value): bool
    {
        $normalized = strtolower($value);

        return str_contains($normalized, 'localhost')
            || str_contains($normalized, '127.0.0.1')
            || str_contains($normalized, '/sandbox/')
            || str_contains($normalized, '/mock/');
    }

    private function resolveTenantIntegrationMode(): ?string
    {
        $tenant = request()?->attributes->get('tenant');
        $tenantId = is_object($tenant) ? ($tenant->id ?? null) : null;
        if (! is_string($tenantId) || $tenantId === '') {
            return null;
        }

        $settings = TenantSetting::query()
            ->where('tenant_id', $tenantId)
            ->first(['metadata']);
        $configured = strtolower((string) data_get($settings?->metadata, 'integration_mode', ''));

        return in_array($configured, [self::SANDBOX, self::PRODUCTION], true)
            ? $configured
            : null;
    }
}
