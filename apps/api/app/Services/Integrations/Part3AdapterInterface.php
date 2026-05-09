<?php

namespace App\Services\Integrations;

interface Part3AdapterInterface
{
    public function ingestInboundMessage(string $channel, array $payload): array;

    public function sendOutboundMessage(string $channel, array $payload): array;

    public function notifyTeams(array $payload): array;

    public function handleAiReception(array $payload): array;

    public function graphAvailability(array $payload): array;

    public function graphBook(array $payload): array;

    public function graphBookingUpdate(array $payload): array;

    public function graphBookingCancel(array $payload): array;

    public function runWorkflow(array $payload): array;

    public function unifiedReporting(array $payload): array;

    public function applyRetentionPolicy(array $payload): array;

    public function runGovernanceDrill(array $payload): array;
}
