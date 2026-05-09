<?php

namespace App\Services\Providers;

interface ProviderAdapterInterface
{
    public function testConnection(array $credentials): array;

    /**
     * @return array<int, array{
     *   sid: string|null,
     *   phone_number: string,
     *   friendly_name: string|null,
     *   capabilities: array<string, bool>
     * }>
     */
    public function fetchIncomingPhoneNumbers(array $credentials): array;

    public function validateNumberOwnership(array $credentials, string $phoneNumber): array;

    public function verifyWebhookSignature(string $rawPayload, array $headers, array $payload, array $credentials): bool;

    public function normalizeWebhookEvent(array $payload): array;

    /**
     * Place an outbound call via the provider API.
     *
     * Returns ['ok' => true, 'provider_call_id' => '...'] on success or
     * ['ok' => false, 'code' => '...', 'message' => '...'] on failure.
     */
    public function makeOutboundCall(array $credentials, string $to, string $from, string $twimlUrl, string $statusCallbackUrl): array;
}
