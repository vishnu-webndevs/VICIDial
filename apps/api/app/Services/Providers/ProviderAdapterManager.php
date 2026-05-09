<?php

namespace App\Services\Providers;

use InvalidArgumentException;

class ProviderAdapterManager
{
    public function __construct(
        private readonly TwilioAdapter $twilioAdapter,
        private readonly VonageAdapter $vonageAdapter,
    ) {
    }

    public function for(string $providerType): ProviderAdapterInterface
    {
        return match ($providerType) {
            'twilio' => $this->twilioAdapter,
            'vonage' => $this->vonageAdapter,
            default => throw new InvalidArgumentException("Unsupported provider type [{$providerType}]"),
        };
    }
}
