<?php

namespace App\Services\Integrations;

use App\Support\IntegrationMode;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class SandboxPart3Adapter extends MockPart3Adapter
{
    public function __construct(private readonly IntegrationMode $integrationMode)
    {
    }

    public function ingestInboundMessage(string $channel, array $payload): array
    {
        return $this->request("messaging-{$channel}-inbound", $payload);
    }

    public function sendOutboundMessage(string $channel, array $payload): array
    {
        $response = $this->request("messaging-{$channel}-outbound", $payload);

        return [
            'mode' => 'sandbox',
            'channel' => $channel,
            'provider_message_id' => (string) ($response['provider_message_id'] ?? ''),
            'status' => (string) ($response['status'] ?? 'sent'),
            'body' => $payload['body'],
            'media' => $payload['media'] ?? [],
            'sent_at' => now()->toISOString(),
            'delivered_at' => now()->toISOString(),
        ];
    }

    public function notifyTeams(array $payload): array
    {
        $response = $this->request('teams-notify', $payload);

        return [
            'mode' => 'sandbox',
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
        $response = $this->request('ai-handle', $payload);

        return [
            'mode' => 'sandbox',
            'decision' => (string) ($response['decision'] ?? 'capture_message'),
            'confidence' => (float) ($response['confidence'] ?? 0.5),
            'captured_message' => $response['captured_message'] ?? null,
            'recommended_route' => $response['recommended_route'] ?? null,
            'processed_at' => now()->toISOString(),
        ];
    }

    public function graphAvailability(array $payload): array
    {
        $response = $this->request('graph-availability', $payload);

        return [
            'mode' => 'sandbox',
            'slots' => (array) ($response['slots'] ?? []),
            'duration_minutes' => (int) $payload['duration_minutes'],
        ];
    }

    public function graphBook(array $payload): array
    {
        $response = $this->request('graph-booking', $payload);

        return [
            'mode' => 'sandbox',
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
        $response = $this->request('graph-booking-update', $payload);

        return [
            'mode' => 'sandbox',
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
        $response = $this->request('graph-booking-cancel', $payload);

        return [
            'mode' => 'sandbox',
            'booking_id' => (string) ($response['booking_id'] ?? ($payload['booking_id'] ?? '')),
            'calendar_event_id' => (string) ($response['calendar_event_id'] ?? ($payload['calendar_event_id'] ?? '')),
            'status' => (string) ($response['status'] ?? 'canceled'),
            'canceled_at' => (string) ($response['canceled_at'] ?? now()->toISOString()),
            'reason' => $payload['reason'] ?? null,
        ];
    }

    public function runWorkflow(array $payload): array
    {
        $response = $this->request('workflow-run', $payload);

        return [
            'mode' => 'sandbox',
            'run_id' => (string) ($response['run_id'] ?? ''),
            'workflow_key' => $payload['workflow_key'],
            'status' => (string) ($response['status'] ?? 'completed'),
            'output' => (array) ($response['output'] ?? []),
        ];
    }

    public function unifiedReporting(array $payload): array
    {
        $response = $this->request('reporting-unified', $payload);

        return [
            'mode' => 'sandbox',
            'kpis' => (array) ($response['kpis'] ?? []),
            'ai' => (array) ($response['ai'] ?? []),
            'filters' => $payload,
        ];
    }

    public function applyRetentionPolicy(array $payload): array
    {
        $response = $this->request('governance-retention', $payload);

        return [
            'mode' => 'sandbox',
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
        $response = $this->request('governance-drill', $payload);

        return [
            'mode' => 'sandbox',
            'drill_id' => (string) ($response['drill_id'] ?? ''),
            'scenario' => $payload['scenario'],
            'status' => (string) ($response['status'] ?? 'completed'),
            'rto_minutes' => (int) ($response['rto_minutes'] ?? 0),
            'rpo_minutes' => (int) ($response['rpo_minutes'] ?? 0),
        ];
    }

    private function request(string $operation, array $payload): array
    {
        if (! $this->integrationMode->isSandbox()) {
            throw new RuntimeException('SandboxPart3Adapter cannot be used outside sandbox mode.');
        }

        try {
            $base = $this->integrationMode->sandboxBaseUrl();
            $url = "{$base}/part3/{$operation}";
            $response = Http::acceptJson()->timeout(15)->post($url, $payload);
            if (! $response->ok()) {
                return [];
            }

            $json = $response->json();

            return is_array($json) ? $json : [];
        } catch (Throwable) {
            return [];
        }
    }
}
