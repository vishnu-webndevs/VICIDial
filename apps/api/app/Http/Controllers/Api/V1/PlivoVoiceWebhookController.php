<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CallEvent;
use App\Models\CallSession;
use App\Models\DialQueueItem;
use App\Models\Lead;
use App\Models\ProviderAccount;
use App\Models\TenantSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class PlivoVoiceWebhookController extends Controller
{
    public function xmlOutbound(Request $request): Response
    {
        $callSessionId = (string) $request->query('call_session_id', '');
        $token = (string) $request->query('token', '');

        Log::info('Plivo xmlOutbound webhook triggered', [
            'call_session_id' => $callSessionId,
            'method' => $request->method(),
            'query' => $request->query(),
            'ip' => $request->ip(),
        ]);

        if ($callSessionId === '') {
            $xml = $this->wrapPlivoXml(
                '<Speak>Please hold while we connect your call.</Speak><Wait length="60"/>'
            );
            return $this->plivoXmlResponse($xml);
        }

        $call = CallSession::query()->where('id', $callSessionId)->first();
        if (!$call) {
            Log::warning('Plivo xmlOutbound: CallSession not found', ['call_session_id' => $callSessionId]);
            return $this->plivoXmlResponse($this->wrapPlivoXml('<Hangup/>'));
        }

        $metadata = (array) ($call->metadata ?? []);
        $dialMode = (string) ($metadata['dial_mode'] ?? 'normal');
        $expected = (string) ($metadata['twiml_token'] ?? '');

        if ($expected !== '' && !hash_equals($expected, $token)) {
            Log::warning('Plivo xmlOutbound: Invalid token', ['call_session_id' => $callSessionId]);
            return $this->plivoXmlResponse($this->wrapPlivoXml('<Hangup/>'));
        }

        if ($dialMode === 'missed_call') {
            return $this->plivoXmlResponse($this->wrapPlivoXml('<Wait length="2"/><Hangup/>'));
        }

        if ($dialMode === 'auto_dialer') {
            $tenantSetting = TenantSetting::query()->where('tenant_id', $call->tenant_id)->first();
            $voiceLocale = $tenantSetting?->voice_locale ?? 'en-US';
            $prompt = (string) ($metadata['tts_prompt'] ?? 'Press 1 if you are interested.');

            $actionUrl = url('/api/webhooks/plivo/gather-result?call_session_id=' . $callSessionId);
            if (str_starts_with(config('app.url'), 'https://') && str_starts_with($actionUrl, 'http://')) {
                $actionUrl = str_replace('http://', 'https://', $actionUrl);
            }

            $escapedPrompt = htmlspecialchars($prompt, ENT_QUOTES);
            $xml = $this->wrapPlivoXml(implode('', [
                '<GetDigits action="' . htmlspecialchars($actionUrl, ENT_QUOTES) . '" method="POST" numDigits="1" timeout="10">',
                '<Speak voice="WOMAN">' . $escapedPrompt . '</Speak>',
                '</GetDigits>',
                '<Speak voice="WOMAN">No input received. Goodbye.</Speak>',
                '<Hangup/>',
            ]));

            return $this->plivoXmlResponse($xml);
        }

        // Normal mode (agent bridge)
        $agentId = (string) ($metadata['agent_id'] ?? '');
        if ($agentId !== '') {
            $agent = \App\Models\Agent::query()->find($agentId);
            if ($agent) {
                $agentMetadata = (array) ($agent->metadata ?? []);
                $callingMethod = (string) ($agentMetadata['calling_method'] ?? 'phone');
                $callerId = htmlspecialchars((string) ($call->from_number ?? ''), ENT_QUOTES);

                if ($callingMethod === 'webrtc') {
                    $sipEndpoint = (string) ($agentMetadata['plivo_endpoint_sip'] ?? ('sip:agent_' . str_replace('-', '_', $agent->id) . '@phone.plivo.com'));
                    $xml = $this->wrapPlivoXml(implode('', [
                        '<Speak voice="WOMAN">Connecting you now.</Speak>',
                        '<Dial callerId="' . $callerId . '">',
                        '<User>' . htmlspecialchars($sipEndpoint, ENT_QUOTES) . '</User>',
                        '</Dial>',
                    ]));
                    return $this->plivoXmlResponse($xml);
                } else {
                    $dest = htmlspecialchars((string) ($agentMetadata['destination_number'] ?? ''), ENT_QUOTES);
                    if ($dest !== '') {
                        $xml = $this->wrapPlivoXml(implode('', [
                            '<Speak voice="WOMAN">Connecting you now.</Speak>',
                            '<Dial callerId="' . $callerId . '">',
                            '<Number>' . $dest . '</Number>',
                            '</Dial>',
                        ]));
                        return $this->plivoXmlResponse($xml);
                    }
                }
            }
        }

        return $this->plivoXmlResponse($this->wrapPlivoXml('<Hangup/>'));
    }

    public function gatherResult(Request $request): Response
    {
        $payload = $request->all();
        $callSessionId = (string) $request->query('call_session_id', '');

        Log::info('Plivo gatherResult webhook triggered', [
            'call_session_id' => $callSessionId,
            'payload' => $payload,
        ]);

        $callSession = CallSession::query()->where('id', $callSessionId)->first();
        if ($callSession) {
            $digits = trim((string) ($payload['Digits'] ?? ''));
            $metadata = (array) ($callSession->metadata ?? []);
            $metadata['digits_pressed'] = $digits;
            $metadata['gather_completed_at'] = now()->toIso8601String();
            $metadata['lead_status_after'] = $digits === '1' ? 'qualified' : 'follow_up';
            $callSession->metadata = $metadata;

            if (!in_array($callSession->status, ['completed', 'failed', 'busy', 'no_answer', 'timeout', 'rejected', 'canceled'], true)) {
                $effectiveEnd = now();
                $effectiveStart = $callSession->started_at ?: $callSession->created_at ?: $effectiveEnd;
                $callSession->status = 'completed';
                $callSession->runtime_state = 'completed';
                $callSession->ended_at = $effectiveEnd;
                $callSession->duration_seconds = max(0, $effectiveStart->diffInSeconds($effectiveEnd));
            }
            $callSession->save();

            $queueItemId = (string) ($metadata['queue_item_id'] ?? '');
            if ($queueItemId !== '') {
                DialQueueItem::query()
                    ->where('tenant_id', $callSession->tenant_id)
                    ->where('id', $queueItemId)
                    ->update([
                        'status' => 'completed',
                        'processed_at' => now(),
                        'failure_reason' => null,
                        'available_at' => null,
                    ]);
            }

            CallEvent::query()->create([
                'tenant_id' => $callSession->tenant_id,
                'call_session_id' => $callSession->id,
                'provider_account_id' => $callSession->provider_account_id,
                'event_type' => 'call.gather_digits',
                'provider_event_type' => 'plivo.gather',
                'status_after' => $callSession->status,
                'payload' => [
                    'digits' => $digits,
                    'lead_id' => (string) ($metadata['lead_id'] ?? ''),
                ],
                'occurred_at' => now(),
            ]);

            $leadId = (string) ($metadata['lead_id'] ?? '');
            if ($leadId !== '') {
                $lead = Lead::query()->where('id', $leadId)->first();
                if ($lead) {
                    if ($digits === '1') {
                        $lead->status = 'qualified';
                        $lead->last_disposition = array_merge((array) $lead->last_disposition, ['reason' => 'Interested']);
                    } else {
                        $lead->status = 'follow_up';
                        $lead->last_disposition = array_merge((array) $lead->last_disposition, ['reason' => 'Call ended without key press or unrecognized key']);
                    }
                    $lead->save();
                }
            }
        }

        return $this->plivoXmlResponse($this->wrapPlivoXml('<Hangup/>'));
    }

    public function inbound(Request $request): Response
    {
        $payload = $request->all();
        $callUuid = (string) ($payload['CallUUID'] ?? '');
        $from = (string) ($payload['From'] ?? '');
        $to = (string) ($payload['To'] ?? '');

        $provider = ProviderAccount::query()
            ->where('provider_type', 'plivo')
            ->where('status', 'active')
            ->first();

        if ($provider && $callUuid !== '') {
            CallSession::query()->firstOrCreate([
                'tenant_id' => $provider->tenant_id,
                'provider_account_id' => $provider->id,
                'provider_call_id' => $callUuid,
            ], [
                'direction' => 'inbound',
                'status' => 'in_progress',
                'runtime_state' => 'initiated',
                'from_number' => $from,
                'to_number' => $to,
                'metadata' => ['provider' => 'plivo'],
                'started_at' => now(),
            ]);
        }

        $xml = $this->wrapPlivoXml('<Speak voice="WOMAN">Thank you for calling. Please stay on the line.</Speak><Wait length="30"/>');
        return $this->plivoXmlResponse($xml);
    }

    private function wrapPlivoXml(string $inner): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><Response>' . $inner . '</Response>';
    }

    private function plivoXmlResponse(string $xml): Response
    {
        return response($xml, 200, ['Content-Type' => 'text/xml']);
    }
}
