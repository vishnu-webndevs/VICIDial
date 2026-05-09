<?php

namespace App\Services\Integrations;

use App\Support\IntegrationMode;
use RuntimeException;

class Part3AdapterManager
{
    public function __construct(
        private readonly SandboxPart3Adapter $sandboxAdapter,
        private readonly HttpPart3Adapter $httpAdapter,
        private readonly IntegrationMode $integrationMode,
    ) {
    }

    public function adapter(): Part3AdapterInterface
    {
        if ($this->integrationMode->isSandbox()) {
            return $this->sandboxAdapter;
        }

        if ((bool) config('services.part3.enabled', false) === true) {
            return $this->httpAdapter;
        }

        throw new RuntimeException('Production mode requires PART3_INTEGRATIONS_ENABLED=true with live endpoints.');
    }
}
