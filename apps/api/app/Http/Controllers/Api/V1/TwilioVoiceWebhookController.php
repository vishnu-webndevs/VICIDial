<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
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
        if (! $provider) {
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

            return $this->twimlResponse($this->wrapTwiml('<Say voice="alice">Thank you. Goodbye.</Say>'));
        }

        $digits = trim((string) ($payload['Digits'] ?? ''));
        if ($digits === '') {
            return $this->twimlResponse($this->gatherTwiml());
        }

        $extension = Extension::query()
            ->where('tenant_id', $tenantId)
            ->where('extension', $digits)
            ->first();

        if (! $extension) {
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

        if (! $ringGroup) {
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
        $callSession->routed_to = 'ring_group:'.$ringGroup->id;
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
        if (! $provider) {
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
            'storage_url' => $recordingUrl !== '' ? $recordingUrl.'.mp3' : null,
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

        return $this->twimlResponse($this->wrapTwiml('<Say voice="alice">Message received. Goodbye.</Say><Hangup/>'));
    }

    public function transfer(Request $request): Response
    {
        $payload = $request->all();
        $provider = $this->resolveTwilioProvider($payload);
        if (! $provider) {
            return $this->twimlResponse($this->wrapTwiml(''));
        }

        $callSessionId = (string) $request->query('call_session_id', '');
        $to = (string) $request->query('to', '');
        $mode = (string) $request->query('mode', 'warm');
        if ($callSessionId === '' || $to === '') {
            return $this->twimlResponse($this->wrapTwiml('<Say voice="alice">Transfer unavailable.</Say><Hangup/>'));
        }

        $callSession = CallSession::query()
            ->where('tenant_id', $provider->tenant_id)
            ->where('id', $callSessionId)
            ->first();
        if (! $callSession) {
            return $this->twimlResponse($this->wrapTwiml('<Say voice="alice">Transfer unavailable.</Say><Hangup/>'));
        }

        $callSession->runtime_state = 'transfer';
        $callSession->routed_to = 'transfer:'.$to;
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
        $action = rtrim((string) config('app.url'), '/').'/api/webhooks/twilio/voice?mode=post_dial';
        $inner = '';
        if ($mode === 'warm') {
            $inner .= '<Say voice="alice">Please hold while we connect your call.</Say>';
        }
        if (($policy['enabled'] ?? false) && ($policy['require_consent'] ?? false)) {
            $prompt = htmlspecialchars((string) ($policy['consent_prompt'] ?? 'This call may be recorded.'), ENT_QUOTES);
            $inner .= '<Say voice="alice">'.$prompt.'</Say>';
        }
        $recordAttr = ($policy['enabled'] ?? false) ? ' record="record-from-answer"' : '';
        $inner .= '<Dial timeout="20" action="'.$action.'" method="POST"'.$recordAttr.'><Number>'.htmlspecialchars($to, ENT_QUOTES).'</Number></Dial>';

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
        $action = rtrim((string) config('app.url'), '/').'/api/webhooks/twilio/voice';

        return $this->wrapTwiml(implode('', [
            '<Say voice="alice">Please enter your extension, then press pound.</Say>',
            '<Gather input="dtmf" numDigits="6" finishOnKey="#" timeout="6" action="'.$action.'" method="POST">',
            '</Gather>',
            '<Say voice="alice">We did not receive any input.</Say>',
            '<Redirect method="POST">'.$action.'</Redirect>',
        ]));
    }

    private function dialRingGroupTwiml(string $tenantId, array $numbers): string
    {
        $action = rtrim((string) config('app.url'), '/').'/api/webhooks/twilio/voice?mode=post_dial';
        $policy = $this->resolveRecordingPolicy($tenantId, $numbers);

        $dialNouns = '';
        foreach ($numbers as $number) {
            $dialNouns .= '<Number>'.htmlspecialchars($number, ENT_QUOTES).'</Number>';
        }

        $recordAttr = ($policy['enabled'] ?? false) ? ' record="record-from-answer"' : '';
        return $this->wrapTwiml(implode('', [
            (($policy['enabled'] ?? false) && ($policy['require_consent'] ?? false))
                ? '<Say voice="alice">'.htmlspecialchars((string) ($policy['consent_prompt'] ?? 'This call may be recorded.'), ENT_QUOTES).'</Say>'
                : '',
            '<Dial timeout="20" action="'.$action.'" method="POST"'.$recordAttr.'>',
            $dialNouns,
            '</Dial>',
            '<Redirect method="POST">'.$action.'</Redirect>',
        ]));
    }

    private function voicemailTwiml(string $callSid, ?string $prefixMessage = null): string
    {
        $action = rtrim((string) config('app.url'), '/').'/api/webhooks/twilio/voice/voicemail';

        $body = '';
        if ($prefixMessage) {
            $body .= '<Say voice="alice">'.htmlspecialchars($prefixMessage, ENT_QUOTES).'</Say>';
        }
        $body .= '<Say voice="alice">Please leave a message after the beep.</Say>';
        $body .= '<Record playBeep="true" maxLength="120" action="'.$action.'" method="POST"/>';
        $body .= '<Say voice="alice">No message received. Goodbye.</Say><Hangup/>';

        return $this->wrapTwiml($body);
    }

    private function wrapTwiml(string $inner): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><Response>'.$inner.'</Response>';
    }

    private function twimlResponse(string $twiml): Response
    {
        return response($twiml, 200, ['Content-Type' => 'text/xml']);
    }

    private function resolveRecordingPolicy(string $tenantId, array $destinations = []): array
    {
        if ($tenantId === '') {
            return ['enabled' => false];
        }
        $setting = TenantSetting::query()->where('tenant_id', $tenantId)->first();
        $metadata = (array) ($setting?->metadata ?? []);
        $policy = (array) ($metadata['recording_policy'] ?? []);

        $base = array_merge([
            'enabled' => false,
            'require_consent' => false,
            'consent_prompt' => 'This call may be recorded.',
            'destination_overrides' => [],
        ], $policy);

        if ($destinations === []) {
            return $base;
        }

        $overrides = (array) ($base['destination_overrides'] ?? []);
        $enabledAny = false;
        $requireConsentAny = false;
        $consentPrompt = (string) ($base['consent_prompt'] ?? 'This call may be recorded.');

        foreach ($destinations as $destination) {
            $destination = (string) $destination;
            $destinationPolicy = $base;

            foreach ($overrides as $override) {
                if (! is_array($override)) {
                    continue;
                }
                $pattern = (string) ($override['pattern'] ?? '');
                if ($pattern === '' || ! $this->destinationMatches($pattern, $destination)) {
                    continue;
                }

                if (array_key_exists('enabled', $override)) {
                    $destinationPolicy['enabled'] = (bool) $override['enabled'];
                }
                if (array_key_exists('require_consent', $override)) {
                    $destinationPolicy['require_consent'] = (bool) $override['require_consent'];
                }
                if (array_key_exists('consent_prompt', $override) && is_string($override['consent_prompt'])) {
                    $destinationPolicy['consent_prompt'] = (string) $override['consent_prompt'];
                }

                break;
            }

            if (($destinationPolicy['enabled'] ?? false) === true) {
                $enabledAny = true;
                if (($destinationPolicy['require_consent'] ?? false) === true) {
                    $requireConsentAny = true;
                    $consentPrompt = (string) ($destinationPolicy['consent_prompt'] ?? $consentPrompt);
                }
            }
        }

        return array_merge($base, [
            'enabled' => $enabledAny,
            'require_consent' => $requireConsentAny,
            'consent_prompt' => $consentPrompt,
        ]);
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

    public function gatherResult(Request $request): Response
    {
        $payload = $request->all();
        $callSessionId = (string) $request->query('call_session_id', '');
        
        $callSession = CallSession::query()->where('id', $callSessionId)->first();
        if (! $callSession) {
            return $this->twimlResponse($this->wrapTwiml('<Hangup/>'));
        }

        $digits = trim((string) ($payload['Digits'] ?? ''));
        $leadId = (string) ($callSession->metadata['lead_id'] ?? '');

        if ($leadId !== '') {
            $lead = \App\Models\Lead::query()->where('id', $leadId)->first();
            if ($lead) {
                if ($digits === '1') {
                    $lead->status = 'qualified';
                    $lead->last_disposition = array_merge((array) $lead->last_disposition, ['reason' => 'Interested']);
                } else {
                    $lead->status = 'follow_up';
                    $lead->last_disposition = array_merge((array) $lead->last_disposition, ['reason' => 'Call ended without key press']);
                }
                $lead->save();
            }
        }

        return $this->twimlResponse($this->wrapTwiml('<Say voice="alice">Thank you. Goodbye.</Say><Hangup/>'));
    }
}
