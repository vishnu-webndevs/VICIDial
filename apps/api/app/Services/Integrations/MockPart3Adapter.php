<?php

namespace App\Services\Integrations;

use Illuminate\Support\Str;

class MockPart3Adapter implements Part3AdapterInterface
{
    public function ingestInboundMessage(string $channel, array $payload): array
    {
        return [
            'mode' => 'mock',
            'channel' => $channel,
            'tenant_id' => $payload['tenant_id'],
            'from' => $payload['from'],
            'to' => $payload['to'] ?? null,
            'body' => $payload['body'],
        ];
    }

    public function sendOutboundMessage(string $channel, array $payload): array
    {
        return [
            'mode' => 'mock',
            'channel' => $channel,
            'provider_message_id' => 'msg_mock_'.Str::lower(Str::random(18)),
            'status' => 'sent_mock',
            'body' => $payload['body'],
            'media' => $payload['media'] ?? [],
            'sent_at' => now()->toISOString(),
            'delivered_at' => now()->toISOString(),
        ];
    }

    public function notifyTeams(array $payload): array
    {
        return [
            'mode' => 'mock',
            'delivery' => 'mock',
            'tenant_id' => $payload['tenant_id'],
            'title' => $payload['title'],
            'message' => $payload['message'],
            'severity' => $payload['severity'] ?? 'info',
            'delivered_at' => now()->toISOString(),
        ];
    }

    public function handleAiReception(array $payload): array
    {
        $confidence = round((float) ($payload['confidence_threshold'] ?? 0.78), 2);
        $decision = $confidence >= 0.70 ? 'auto_route' : 'capture_message';

        return [
            'mode' => 'mock',
            'decision' => $decision,
            'confidence' => $confidence,
            'captured_message' => $decision === 'capture_message' ? (string) $payload['transcript'] : null,
            'recommended_route' => $decision === 'auto_route' ? 'ring_group:default' : null,
            'processed_at' => now()->toISOString(),
        ];
    }

    public function graphAvailability(array $payload): array
    {
        return [
            'mode' => 'mock',
            'slots' => [
                ['start' => now()->addDay()->setTime(10, 0)->toISOString(), 'end' => now()->addDay()->setTime(10, 30)->toISOString()],
                ['start' => now()->addDay()->setTime(14, 0)->toISOString(), 'end' => now()->addDay()->setTime(14, 30)->toISOString()],
            ],
            'duration_minutes' => (int) $payload['duration_minutes'],
        ];
    }

    public function graphBook(array $payload): array
    {
        return [
            'mode' => 'mock',
            'booking_id' => 'graph_mock_'.Str::lower(Str::random(20)),
            'calendar_event_id' => 'evt_mock_'.Str::lower(Str::random(20)),
            'confirmation_sent' => true,
            'start' => $payload['start'],
            'end' => $payload['end'],
            'attendee_email' => $payload['attendee_email'],
            'subject' => $payload['subject'],
        ];
    }

    public function graphBookingUpdate(array $payload): array
    {
        return [
            'mode' => 'mock',
            'booking_id' => (string) ($payload['booking_id'] ?? ''),
            'calendar_event_id' => (string) ($payload['calendar_event_id'] ?? ''),
            'status' => 'updated',
            'start' => $payload['start'] ?? null,
            'end' => $payload['end'] ?? null,
            'attendee_email' => $payload['attendee_email'] ?? null,
            'subject' => $payload['subject'] ?? null,
            'updated_at' => now()->toISOString(),
        ];
    }

    public function graphBookingCancel(array $payload): array
    {
        return [
            'mode' => 'mock',
            'booking_id' => (string) ($payload['booking_id'] ?? ''),
            'calendar_event_id' => (string) ($payload['calendar_event_id'] ?? ''),
            'status' => 'canceled',
            'canceled_at' => now()->toISOString(),
            'reason' => $payload['reason'] ?? null,
        ];
    }

    public function runWorkflow(array $payload): array
    {
        return [
            'mode' => 'mock',
            'run_id' => 'wf_mock_'.Str::lower(Str::random(20)),
            'workflow_key' => $payload['workflow_key'],
            'status' => 'completed',
            'output' => [
                'steps_executed' => 3,
                'actions' => ['sms_sent', 'task_created', 'notification_posted'],
            ],
        ];
    }

    public function unifiedReporting(array $payload): array
    {
        return [
            'mode' => 'mock',
            'kpis' => [
                'voice_calls' => 1248,
                'sms_messages' => 3492,
                'whatsapp_messages' => 688,
                'voicemails' => 97,
                'sla_breaches' => 4,
            ],
            'ai' => [
                'assistant_handoffs' => 43,
                'average_confidence' => 0.81,
            ],
            'filters' => $payload,
        ];
    }

    public function applyRetentionPolicy(array $payload): array
    {
        return [
            'mode' => 'mock',
            'policy_id' => 'ret_mock_'.Str::lower(Str::random(16)),
            'tenant_id' => $payload['tenant_id'],
            'retention_days' => (int) $payload['retention_days'],
            'pii_redaction_enabled' => (bool) ($payload['pii_redaction_enabled'] ?? false),
            'audit_export_email' => $payload['audit_export_email'] ?? null,
            'effective_at' => now()->toISOString(),
        ];
    }

    public function runGovernanceDrill(array $payload): array
    {
        return [
            'mode' => 'mock',
            'drill_id' => 'drill_mock_'.Str::lower(Str::random(18)),
            'scenario' => $payload['scenario'],
            'status' => 'completed',
            'rto_minutes' => 17,
            'rpo_minutes' => 5,
        ];
    }
}
