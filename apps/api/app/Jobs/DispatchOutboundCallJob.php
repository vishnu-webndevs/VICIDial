<?php

namespace App\Jobs;

use App\Models\CallEvent;
use App\Models\CallSession;
use App\Models\ProviderAccount;
use App\Services\Providers\ProviderAdapterManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

class DispatchOutboundCallJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(
        public readonly string $callSessionId,
        public readonly string $providerAccountId,
        public readonly string $twimlUrl,
        public readonly string $statusCallbackUrl,
    ) {
    }

    public function handle(ProviderAdapterManager $adapterManager): void
    {
        $call = CallSession::query()->find($this->callSessionId);
        if (! $call instanceof CallSession) {
            return;
        }

        // Skip if the call was already cancelled/ended before the job ran.
        if (in_array($call->status, ['canceled', 'failed', 'completed'], true)) {
            return;
        }

        $provider = ProviderAccount::query()->find($this->providerAccountId);
        if (! $provider instanceof ProviderAccount) {
            $this->failCall($call, 'Provider account not found.');

            return;
        }

        $from = (string) ($call->from_number ?? '');
        $to = (string) ($call->to_number ?? '');
        $metadata = (array) ($call->metadata ?? []);
        $dialMode = (string) ($metadata['dial_mode'] ?? 'normal');




        if ($to === '') {
            $this->failCall($call, 'No valid destination number or agent configured.');
            return;
        }

        // #region debug-point A:provider-dispatch
        $this->debugReport('A', 'call.dispatch.start', [
            'tenant_id' => (string) $call->tenant_id,
            'call_session_id' => (string) $call->id,
            'provider_account_id' => (string) $provider->id,
            'provider_type' => (string) $provider->provider_type,
            'to' => (string) $to,
            'from' => (string) $from,
            'twiml_url' => (string) $this->twimlUrl,
            'status_callback_url' => (string) $this->statusCallbackUrl,
        ]);
        // #endregion

        $result = $adapterManager
            ->for($provider->provider_type)
            ->makeOutboundCall(
                credentials: (array) $provider->credentials_encrypted,
                to: $to,
                from: $from,
                twimlUrl: $this->twimlUrl,
                statusCallbackUrl: $this->statusCallbackUrl,
            );

        // #region debug-point B:provider-result
        $this->debugReport('B', 'call.dispatch.result', [
            'tenant_id' => (string) $call->tenant_id,
            'call_session_id' => (string) $call->id,
            'provider_account_id' => (string) $provider->id,
            'provider_type' => (string) $provider->provider_type,
            'ok' => (bool) ($result['ok'] ?? false),
            'provider_call_id' => (string) ($result['provider_call_id'] ?? ''),
            'mode' => (string) ($result['mode'] ?? ''),
            'message' => (string) ($result['message'] ?? ''),
        ]);
        // #endregion

        if ($result['ok'] && ! empty($result['provider_call_id'])) {
            $call->provider_call_id = $result['provider_call_id'];
            $call->failure_reason = null;
            $call->save();

            $mode = (string) ($result['mode'] ?? 'live');

            CallEvent::query()->create([
                'tenant_id' => $call->tenant_id,
                'call_session_id' => $call->id,
                'provider_account_id' => $call->provider_account_id,
                'event_type' => 'call.updated',
                'provider_event_type' => 'outbound.dispatched',
                'status_after' => $call->status,
                'payload' => [
                    'provider_call_id' => (string) $result['provider_call_id'],
                    'mode' => $mode,
                ],
                'occurred_at' => now(),
            ]);

            // In sandbox mode, Twilio webhooks cannot reach localhost so we simulate
            // the call lifecycle (ringing → in_progress → completed) via delayed jobs.
            if ($mode === 'sandbox') {
                SimulateSandboxCallProgressionJob::dispatchProgression($call->id);
            }

            return;
        }

        $this->failCall($call, $result['message'] ?? 'Provider dispatch failed.');
    }

    private function failCall(CallSession $call, string $reason): void
    {
        // #region debug-point C:provider-fail
        $this->debugReport('C', 'call.dispatch.fail', [
            'tenant_id' => (string) $call->tenant_id,
            'call_session_id' => (string) $call->id,
            'provider_account_id' => (string) ($call->provider_account_id ?? ''),
            'reason' => (string) $reason,
            'status_before' => (string) ($call->status ?? ''),
        ]);
        // #endregion

        $call->status = 'failed';
        $call->failure_reason = $reason;
        $call->save();

        CallEvent::query()->create([
            'tenant_id' => $call->tenant_id,
            'call_session_id' => $call->id,
            'provider_account_id' => $call->provider_account_id,
            'event_type' => 'call.failed',
            'provider_event_type' => 'outbound.dispatch_error',
            'status_after' => 'failed',
            'payload' => ['message' => $reason],
            'occurred_at' => now(),
        ]);
    }

    private function debugReport(string $hypothesisId, string $event, array $data): void
    {
        $url = $this->debugServerUrl();
        if (! $url) {
            return;
        }

        try {
            Http::timeout(0.5)->post($url, [
                'sessionId' => 'auto-dialer-outbound-calls',
                'runId' => 'pre-fix',
                'hypothesisId' => $hypothesisId,
                'location' => 'DispatchOutboundCallJob',
                'msg' => '[DEBUG] '.$event,
                'data' => $data,
                'ts' => (int) floor(microtime(true) * 1000),
            ]);
        } catch (\Throwable) {
        }
    }

    private function debugServerUrl(): ?string
    {
        static $cached = null;
        static $loaded = false;

        if ($loaded) {
            return $cached;
        }

        $loaded = true;

        try {
            $paths = [
                base_path('.dbg/auto-dialer-outbound-calls.env'),
                dirname(base_path()) . DIRECTORY_SEPARATOR . '.dbg' . DIRECTORY_SEPARATOR . 'auto-dialer-outbound-calls.env',
            ];
            foreach ($paths as $path) {
                if (is_string($path) && is_file($path)) {
                    $contents = (string) file_get_contents($path);
                    foreach (preg_split("/\r\n|\n|\r/", $contents) ?: [] as $line) {
                        if (str_starts_with($line, 'DEBUG_SERVER_URL=')) {
                            $cached = trim(substr($line, strlen('DEBUG_SERVER_URL=')));
                            break 2;
                        }
                    }
                }
            }
        } catch (\Throwable) {
        }

        if (! is_string($cached) || $cached === '') {
            $cached = env('DEBUG_SERVER_URL') ?: null;
        }

        return is_string($cached) && $cached !== '' ? $cached : null;
    }
}
