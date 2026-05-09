<?php

namespace App\Services\Integrations;

use Illuminate\Support\Facades\Http;

class HttpPart3Adapter extends MockPart3Adapter
{
    public function __construct(
        private readonly array $config,
    ) {
    }

    public function ingestInboundMessage(string $channel, array $payload): array
    {
        if (! $this->isEnabled("messaging.{$channel}")) {
            return parent::ingestInboundMessage($channel, $payload);
        }

        $response = $this->post("messaging.{$channel}.inbound_url", $payload);
        if ($response === null) {
            return parent::ingestInboundMessage($channel, $payload);
        }

        return [
            'mode' => 'live',
            ...$response,
        ];
    }

    public function sendOutboundMessage(string $channel, array $payload): array
    {
        if (! $this->isEnabled("messaging.{$channel}")) {
            return parent::sendOutboundMessage($channel, $payload);
        }

        $response = $this->post("messaging.{$channel}.outbound_url", $payload);
        if ($response === null) {
            return parent::sendOutboundMessage($channel, $payload);
        }

        return [
            'mode' => 'live',
            'channel' => $channel,
            'provider_message_id' => (string) ($response['provider_message_id'] ?? $response['id'] ?? ''),
            'status' => (string) ($response['status'] ?? 'sent'),
            'body' => $payload['body'],
            'media' => $payload['media'] ?? [],
            'sent_at' => (string) ($response['sent_at'] ?? now()->toISOString()),
            'delivered_at' => (string) ($response['delivered_at'] ?? now()->toISOString()),
        ];
    }

    public function notifyTeams(array $payload): array
    {
        if (! $this->isEnabled('teams')) {
            return parent::notifyTeams($payload);
        }

        $response = $this->post('teams.url', $payload);
        if ($response === null) {
            return parent::notifyTeams($payload);
        }

        return [
            'mode' => 'live',
            'delivery' => 'teams_webhook',
            'tenant_id' => $payload['tenant_id'],
            'title' => $payload['title'],
            'message' => $payload['message'],
            'severity' => $payload['severity'] ?? 'info',
            'delivered_at' => (string) ($response['delivered_at'] ?? now()->toISOString()),
        ];
    }

    public function handleAiReception(array $payload): array
    {
        if (! $this->isEnabled('ai')) {
            return parent::handleAiReception($payload);
        }

        $response = $this->post('ai.url', $payload);
        if ($response === null) {
            return parent::handleAiReception($payload);
        }

        return [
            'mode' => 'live',
            'decision' => (string) ($response['decision'] ?? 'capture_message'),
            'confidence' => (float) ($response['confidence'] ?? 0.5),
            'captured_message' => $response['captured_message'] ?? null,
            'recommended_route' => $response['recommended_route'] ?? null,
            'processed_at' => (string) ($response['processed_at'] ?? now()->toISOString()),
        ];
    }

    public function graphAvailability(array $payload): array
    {
        if (! $this->isEnabled('graph')) {
            return parent::graphAvailability($payload);
        }

        $response = $this->post('graph.availability_url', $payload);
        if ($response === null) {
            return parent::graphAvailability($payload);
        }

        return [
            'mode' => 'live',
            'slots' => (array) ($response['slots'] ?? []),
            'duration_minutes' => (int) $payload['duration_minutes'],
        ];
    }

    public function graphBook(array $payload): array
    {
        if (! $this->isEnabled('graph')) {
            return parent::graphBook($payload);
        }

        $response = $this->post('graph.booking_url', $payload);
        if ($response === null) {
            return parent::graphBook($payload);
        }

        return [
            'mode' => 'live',
            'booking_id' => (string) ($response['booking_id'] ?? ''),
            'calendar_event_id' => (string) ($response['calendar_event_id'] ?? ''),
            'confirmation_sent' => (bool) ($response['confirmation_sent'] ?? true),
            'start' => $payload['start'],
            'end' => $payload['end'],
            'attendee_email' => $payload['attendee_email'],
            'subject' => $payload['subject'],
        ];
    }

    public function graphBookingUpdate(array $payload): array
    {
        if (! $this->isEnabled('graph')) {
            return parent::graphBookingUpdate($payload);
        }

        $response = $this->post('graph.booking_update_url', $payload);
        if ($response === null) {
            return parent::graphBookingUpdate($payload);
        }

        return [
            'mode' => 'live',
            'booking_id' => (string) ($response['booking_id'] ?? ($payload['booking_id'] ?? '')),
            'calendar_event_id' => (string) ($response['calendar_event_id'] ?? ($payload['calendar_event_id'] ?? '')),
            'status' => (string) ($response['status'] ?? 'updated'),
            'start' => $payload['start'] ?? null,
            'end' => $payload['end'] ?? null,
            'attendee_email' => $payload['attendee_email'] ?? null,
            'subject' => $payload['subject'] ?? null,
            'updated_at' => (string) ($response['updated_at'] ?? now()->toISOString()),
        ];
    }

    public function graphBookingCancel(array $payload): array
    {
        if (! $this->isEnabled('graph')) {
            return parent::graphBookingCancel($payload);
        }

        $response = $this->post('graph.booking_cancel_url', $payload);
        if ($response === null) {
            return parent::graphBookingCancel($payload);
        }

        return [
            'mode' => 'live',
            'booking_id' => (string) ($response['booking_id'] ?? ($payload['booking_id'] ?? '')),
            'calendar_event_id' => (string) ($response['calendar_event_id'] ?? ($payload['calendar_event_id'] ?? '')),
            'status' => (string) ($response['status'] ?? 'canceled'),
            'canceled_at' => (string) ($response['canceled_at'] ?? now()->toISOString()),
            'reason' => $payload['reason'] ?? null,
        ];
    }

    public function runWorkflow(array $payload): array
    {
        if (! $this->isEnabled('workflow')) {
            return parent::runWorkflow($payload);
        }

        $response = $this->post('workflow.url', $payload);
        if ($response === null) {
            return parent::runWorkflow($payload);
        }

        return [
            'mode' => 'live',
            'run_id' => (string) ($response['run_id'] ?? ''),
            'workflow_key' => $payload['workflow_key'],
            'status' => (string) ($response['status'] ?? 'queued'),
            'output' => (array) ($response['output'] ?? []),
        ];
    }

    public function unifiedReporting(array $payload): array
    {
        if (! $this->isEnabled('reporting')) {
            return parent::unifiedReporting($payload);
        }

        $response = $this->post('reporting.url', $payload);
        if ($response === null) {
            return parent::unifiedReporting($payload);
        }

        return [
            'mode' => 'live',
            'kpis' => (array) ($response['kpis'] ?? []),
            'ai' => (array) ($response['ai'] ?? []),
            'filters' => $payload,
        ];
    }

    public function applyRetentionPolicy(array $payload): array
    {
        if (! $this->isEnabled('governance')) {
            return parent::applyRetentionPolicy($payload);
        }

        $response = $this->post('governance.retention_url', $payload);
        if ($response === null) {
            return parent::applyRetentionPolicy($payload);
        }

        return [
            'mode' => 'live',
            'policy_id' => (string) ($response['policy_id'] ?? ''),
            'tenant_id' => $payload['tenant_id'],
            'retention_days' => (int) $payload['retention_days'],
            'pii_redaction_enabled' => (bool) ($payload['pii_redaction_enabled'] ?? false),
            'audit_export_email' => $payload['audit_export_email'] ?? null,
            'effective_at' => (string) ($response['effective_at'] ?? now()->toISOString()),
        ];
    }

    public function runGovernanceDrill(array $payload): array
    {
        if (! $this->isEnabled('governance')) {
            return parent::runGovernanceDrill($payload);
        }

        $response = $this->post('governance.drill_url', $payload);
        if ($response === null) {
            return parent::runGovernanceDrill($payload);
        }

        return [
            'mode' => 'live',
            'drill_id' => (string) ($response['drill_id'] ?? ''),
            'scenario' => $payload['scenario'],
            'status' => (string) ($response['status'] ?? 'queued'),
            'rto_minutes' => (int) ($response['rto_minutes'] ?? 0),
            'rpo_minutes' => (int) ($response['rpo_minutes'] ?? 0),
        ];
    }

    private function post(string $urlKey, array $payload): ?array
    {
        $url = (string) data_get($this->config, $urlKey, '');
        if ($url === '') {
            return null;
        }

        $token = (string) data_get($this->config, 'auth_token', '');
        $request = Http::timeout(10)->acceptJson();
        if ($token !== '') {
            $request = $request->withToken($token);
        }

        $response = $request->post($url, $payload);
        if (! $response->ok()) {
            return null;
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private function isEnabled(string $scope): bool
    {
        return (bool) data_get($this->config, 'enabled', false)
            && (bool) data_get($this->config, "{$scope}.enabled", true);
    }
}
