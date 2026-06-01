<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CallEvent;
use App\Models\CallLeg;
use App\Models\CallSession;
use App\Models\Extension;
use App\Models\ProviderAccount;
use App\Models\RingGroup;
use App\Models\RingGroupMember;
use App\Models\TenantSetting;
use App\Models\VoicemailMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TwilioVoiceWebhookController extends Controller
{
    public function inbound(Request $request): Response
    {
        $payload = $request->all();
        $provider = $this->resolveTwilioProvider($payload);
        if (!$provider) {
            return $this->twimlResponse($this->wrapTwiml(''));
        }

        $mode = (string) $request->query('mode', '');
        $callSid = (string) ($payload['CallSid'] ?? '');
        $from = (string) ($payload['From'] ?? '');
        $to = (string) ($payload['To'] ?? '');
        $tenantId = $provider->tenant_id;

        $callSession = $this->ensureInboundCallSession(
            tenantId: $tenantId,
            providerAccountId: $provider->id,
            providerCallId: $callSid,
            fromNumber: $from,
            toNumber: $to,
        );

        if ($mode === 'post_dial') {
            $dialStatus = (string) ($payload['DialCallStatus'] ?? '');
            if ($dialStatus !== 'completed') {
                return $this->twimlResponse($this->voicemailTwiml($callSid));
            }

            return $this->twimlResponse($this->wrapTwiml('<Say voice="Polly.Joanna">Thank you. Goodbye.</Say>'));
        }

        $digits = trim((string) ($payload['Digits'] ?? ''));
        if ($digits === '') {
            return $this->twimlResponse($this->gatherTwiml());
        }

        $extension = Extension::query()
            ->where('tenant_id', $tenantId)
            ->where('extension', $digits)
            ->first();

        if (!$extension) {
            return $this->twimlResponse($this->voicemailTwiml($callSid, 'Invalid extension.'));
        }

        if ($extension->target_type !== 'ring_group') {
            return $this->twimlResponse($this->voicemailTwiml($callSid, 'Extension cannot be routed.'));
        }

        $ringGroup = RingGroup::query()
            ->where('tenant_id', $tenantId)
            ->where('id', (string) $extension->target_id)
            ->where('active', true)
            ->first();

        if (!$ringGroup) {
            return $this->twimlResponse($this->voicemailTwiml($callSid, 'Ring group is unavailable.'));
        }

        $members = RingGroupMember::query()
            ->where('tenant_id', $tenantId)
            ->where('ring_group_id', $ringGroup->id)
            ->orderBy('priority')
            ->get();

        $numbers = [];
        foreach ($members as $member) {
            if ($member->external_number) {
                $numbers[] = (string) $member->external_number;
            }
        }

        $callSession->runtime_state = 'ringing';
        $callSession->routed_to = 'ring_group:' . $ringGroup->id;
        $callSession->routing_confidence = 100;
        $callSession->metadata = array_merge((array) ($callSession->metadata ?? []), [
            'inbound' => [
                'extension' => $digits,
                'ring_group_id' => $ringGroup->id,
                'strategy' => $ringGroup->strategy,
                'member_count' => count($numbers),
            ],
        ]);
        $callSession->save();

        if ($numbers === []) {
            return $this->twimlResponse($this->voicemailTwiml($callSid, 'No ring group members configured.'));
        }

        CallLeg::query()->create([
            'tenant_id' => $tenantId,
            'call_session_id' => $callSession->id,
            'from_number' => $from,
            'to_number' => implode(',', $numbers),
            'status' => 'ringing',
            'started_at' => now(),
            'metadata' => [
                'type' => 'ring_group_dial',
                'ring_group_id' => $ringGroup->id,
                'extension' => $digits,
                'numbers' => $numbers,
            ],
        ]);

        return $this->twimlResponse($this->dialRingGroupTwiml($tenantId, $numbers));
    }

    public function voicemail(Request $request): Response
    {
        $payload = $request->all();
        $provider = $this->resolveTwilioProvider($payload);
        if (!$provider) {
            return $this->twimlResponse($this->wrapTwiml(''));
        }

        $callSid = (string) ($payload['CallSid'] ?? '');
        $from = (string) ($payload['From'] ?? '');
        $to = (string) ($payload['To'] ?? '');
        $recordingUrl = (string) ($payload['RecordingUrl'] ?? '');
        $recordingDuration = (int) ($payload['RecordingDuration'] ?? 0);

        $callSession = null;
        if ($callSid !== '') {
            $callSession = CallSession::query()
                ->where('tenant_id', $provider->tenant_id)
                ->where('provider_call_id', $callSid)
                ->first();
        }

        $vm = VoicemailMessage::query()->create([
            'tenant_id' => $provider->tenant_id,
            'call_session_id' => $callSession?->id,
            'from_number' => $from,
            'to_number' => $to,
            'storage_url' => $recordingUrl !== '' ? $recordingUrl . '.mp3' : null,
            'status' => 'captured',
            'metadata' => [
                'provider' => 'twilio',
                'recording_url' => $recordingUrl,
                'recording_duration' => $recordingDuration,
                'payload' => $payload,
            ],
        ]);

        if ($callSession) {
            $callSession->recording_url = $vm->storage_url;
            $callSession->recording_duration = $recordingDuration > 0 ? $recordingDuration : null;
            $callSession->runtime_state = 'voicemail';
            $callSession->save();
        }

        return $this->twimlResponse($this->wrapTwiml('<Say voice="Polly.Joanna">Message received. Goodbye.</Say><Hangup/>'));
    }

    public function transfer(Request $request): Response
    {
        $payload = $request->all();
        $provider = $this->resolveTwilioProvider($payload);
        if (!$provider) {
            return $this->twimlResponse($this->wrapTwiml(''));
        }

        $callSessionId = (string) $request->query('call_session_id', '');
        $to = (string) $request->query('to', '');
        $mode = (string) $request->query('mode', 'warm');
        if ($callSessionId === '' || $to === '') {
            return $this->twimlResponse($this->wrapTwiml('<Say voice="Polly.Joanna">Transfer unavailable.</Say><Hangup/>'));
        }

        $callSession = CallSession::query()
            ->where('tenant_id', $provider->tenant_id)
            ->where('id', $callSessionId)
            ->first();
        if (!$callSession) {
            return $this->twimlResponse($this->wrapTwiml('<Say voice="Polly.Joanna">Transfer unavailable.</Say><Hangup/>'));
        }

        $callSession->runtime_state = 'transfer';
        $callSession->routed_to = 'transfer:' . $to;
        $callSession->routing_confidence = 100;
        $callSession->save();

        CallLeg::query()->create([
            'tenant_id' => $provider->tenant_id,
            'call_session_id' => $callSession->id,
            'from_number' => $callSession->from_number,
            'to_number' => $to,
            'status' => 'initiated',
            'started_at' => now(),
            'metadata' => [
                'type' => 'transfer',
                'mode' => $mode,
            ],
        ]);

        $policy = $this->resolveRecordingPolicy($provider->tenant_id, [$to]);
        $action = rtrim((string) config('app.url'), '/') . '/api/webhooks/twilio/voice?mode=post_dial';
        $inner = '';
        if ($mode === 'warm') {
            $inner .= '<Say voice="Polly.Joanna">Please hold while we connect your call.</Say>';
        }
        if (($policy['enabled'] ?? false) && ($policy['require_consent'] ?? false)) {
            $prompt = htmlspecialchars((string) ($policy['consent_prompt'] ?? 'This call may be recorded.'), ENT_QUOTES);
            $inner .= '<Say voice="Polly.Joanna">' . $prompt . '</Say>';
        }
        $recordAttr = ($policy['enabled'] ?? false) ? ' record="record-from-answer"' : '';
        $inner .= '<Dial timeout="20" action="' . $action . '" method="POST"' . $recordAttr . '><Number>' . htmlspecialchars($to, ENT_QUOTES) . '</Number></Dial>';

        return $this->twimlResponse($this->wrapTwiml($inner));
    }

    private function resolveTwilioProvider(array $payload): ?ProviderAccount
    {
        $accountSid = (string) ($payload['AccountSid'] ?? '');
        if ($accountSid === '') {
            return null;
        }

        $providers = ProviderAccount::query()
            ->where('provider_type', 'twilio')
            ->where('status', 'active')
            ->get();

        /** @var ProviderAccount $provider */
        foreach ($providers as $provider) {
            $credentials = (array) $provider->credentials_encrypted;
            if (($credentials['account_sid'] ?? null) === $accountSid) {
                return $provider;
            }
        }

        return null;
    }

    private function ensureInboundCallSession(
        string $tenantId,
        string $providerAccountId,
        string $providerCallId,
        string $fromNumber,
        string $toNumber,
    ): CallSession {
        if ($providerCallId === '') {
            return CallSession::query()->create([
                'tenant_id' => $tenantId,
                'provider_account_id' => $providerAccountId,
                'direction' => 'inbound',
                'status' => 'in_progress',
                'runtime_state' => 'initiated',
                'from_number' => $fromNumber,
                'to_number' => $toNumber,
                'metadata' => [
                    'provider' => 'twilio',
                ],
                'started_at' => now(),
            ]);
        }

        return CallSession::query()->firstOrCreate([
            'tenant_id' => $tenantId,
            'provider_account_id' => $providerAccountId,
            'provider_call_id' => $providerCallId,
        ], [
            'direction' => 'inbound',
            'status' => 'in_progress',
            'runtime_state' => 'initiated',
            'from_number' => $fromNumber,
            'to_number' => $toNumber,
            'metadata' => [
                'provider' => 'twilio',
            ],
            'started_at' => now(),
        ]);
    }

    private function gatherTwiml(): string
    {
        $action = rtrim((string) config('app.url'), '/') . '/api/webhooks/twilio/voice';

        return $this->wrapTwiml(implode('', [
            '<Say voice="Polly.Joanna">Please enter your extension, then press pound.</Say>',
            '<Gather input="dtmf" numDigits="6" finishOnKey="#" timeout="6" action="' . $action . '" method="POST">',
            '</Gather>',
            '<Say voice="Polly.Joanna">We did not receive any input.</Say>',
            '<Redirect method="POST">' . $action . '</Redirect>',
        ]));
    }

    private function dialRingGroupTwiml(string $tenantId, array $numbers): string
    {
        $action = rtrim((string) config('app.url'), '/') . '/api/webhooks/twilio/voice?mode=post_dial';
        $policy = $this->resolveRecordingPolicy($tenantId, $numbers);

        $dialNouns = '';
        foreach ($numbers as $number) {
            $dialNouns .= '<Number>' . htmlspecialchars($number, ENT_QUOTES) . '</Number>';
        }

        $recordAttr = ($policy['enabled'] ?? false) ? ' record="record-from-answer"' : '';
        return $this->wrapTwiml(implode('', [
            (($policy['enabled'] ?? false) && ($policy['require_consent'] ?? false))
            ? '<Say voice="Polly.Joanna">' . htmlspecialchars((string) ($policy['consent_prompt'] ?? 'This call may be recorded.'), ENT_QUOTES) . '</Say>'
            : '',
            '<Dial timeout="20" action="' . $action . '" method="POST"' . $recordAttr . '>',
            $dialNouns,
            '</Dial>',
            '<Redirect method="POST">' . $action . '</Redirect>',
        ]));
    }

    private function voicemailTwiml(string $callSid, ?string $prefixMessage = null): string
    {
        $action = rtrim((string) config('app.url'), '/') . '/api/webhooks/twilio/voice/voicemail';

        $body = '';
        if ($prefixMessage) {
            $body .= '<Say voice="Polly.Joanna">' . htmlspecialchars($prefixMessage, ENT_QUOTES) . '</Say>';
        }
        $body .= '<Say voice="Polly.Joanna">Please leave a message after the beep.</Say>';
        $body .= '<Record playBeep="true" maxLength="120" action="' . $action . '" method="POST"/>';
        $body .= '<Say voice="Polly.Joanna">No message received. Goodbye.</Say><Hangup/>';

        return $this->wrapTwiml($body);
    }

    private function wrapTwiml(string $inner): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><Response>' . $inner . '</Response>';
    }

    private function twimlResponse(string $twiml): Response
    {
        return response($twiml, 200, ['Content-Type' => 'text/xml']);
    }

    private function resolveRecordingPolicy(string $tenantId, array $destinations = []): array
    {
        // Force-disable call recording globally to prevent any Twilio recording charges
        return [
            'enabled' => false,
            'require_consent' => false,
            'consent_prompt' => '',
            'destination_overrides' => [],
        ];
    }

    private function destinationMatches(string $pattern, string $destination): bool
    {
        if (str_starts_with($pattern, 'regex:')) {
            $regex = substr($pattern, 6);
            if ($regex === '') {
                return false;
            }

            return @preg_match($regex, $destination) === 1;
        }

        if (str_ends_with($pattern, '*')) {
            $prefix = substr($pattern, 0, -1);

            return $prefix !== '' && str_starts_with($destination, $prefix);
        }

        return $pattern === $destination;
    }

    public function gatherResult(Request $request): \Illuminate\Http\JsonResponse
    {
        $payload = $request->all();
        $callSessionId = (string) $request->query('call_session_id', '');

        \Illuminate\Support\Facades\Log::info('Twilio gatherResult webhook triggered', [
            'call_session_id' => $callSessionId,
            'payload' => $payload,
            'method' => $request->method(),
            'ip' => $request->ip(),
        ]);

        $callSession = CallSession::query()->where('id', $callSessionId)->first();
        $callSessionFound = $callSession !== null;

        $digits = trim((string) ($payload['Digits'] ?? ''));
        $leadId = '';
        $leadFound = false;

        if ($callSessionFound) {
            $leadId = (string) ($callSession->metadata['lead_id'] ?? '');

            // Update CallSession metadata with the gathered digits and lead status
            $metadata = (array) ($callSession->metadata ?? []);
            $metadata['digits_pressed'] = $digits;
            $metadata['gather_completed_at'] = now()->toIso8601String();
            $metadata['lead_status_after'] = $digits === '1' ? 'qualified' : 'follow_up';
            $callSession->metadata = $metadata;
            $callSession->save();

            // Create a CallEvent for the timeline
            CallEvent::query()->create([
                'tenant_id' => $callSession->tenant_id,
                'call_session_id' => $callSession->id,
                'provider_account_id' => $callSession->provider_account_id,
                'event_type' => 'call.gather_digits',
                'provider_event_type' => 'twilio.gather',
                'status_after' => $callSession->status,
                'payload' => [
                    'digits' => $digits,
                    'lead_id' => $leadId,
                ],
                'occurred_at' => now(),
            ]);

            if ($leadId !== '') {
                $lead = \App\Models\Lead::query()->where('id', $leadId)->first();
                if ($lead) {
                    $leadFound = true;
                    \Illuminate\Support\Facades\Log::info('Twilio gatherResult: Lead found, updating status', [
                        'lead_id' => $leadId,
                        'digits' => $digits,
                    ]);
                    if ($digits === '1') {
                        $lead->status = 'qualified';
                        $lead->last_disposition = array_merge((array) $lead->last_disposition, ['reason' => 'Interested']);
                    } else {
                        $lead->status = 'follow_up';
                        $lead->last_disposition = array_merge((array) $lead->last_disposition, ['reason' => 'Call ended without key press or unrecognized key']);
                    }
                    $lead->save();
                    \Illuminate\Support\Facades\Log::info('Twilio gatherResult: Lead saved successfully', [
                        'lead_id' => $leadId,
                        'status' => $lead->status,
                    ]);
                } else {
                    \Illuminate\Support\Facades\Log::warning('Twilio gatherResult: Lead not found in DB', [
                        'lead_id' => $leadId,
                    ]);
                }
            } else {
                \Illuminate\Support\Facades\Log::warning('Twilio gatherResult: lead_id is missing from CallSession metadata', [
                    'metadata' => $callSession->metadata,
                ]);
            }
        } else {
            \Illuminate\Support\Facades\Log::warning('Twilio gatherResult: CallSession not found', [
                'call_session_id' => $callSessionId,
            ]);
        }

        return response()->json([
            'call_session_found' => $callSessionFound,
            'lead_id' => $leadId,
            'lead_found' => $leadFound,
            'digits' => $digits,
        ]);
    }
}
