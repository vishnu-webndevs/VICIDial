<?php

namespace App\Jobs;

use App\Models\CallEvent;
use App\Models\CallSession;
use App\Models\ProviderAccount;
use App\Services\Providers\ProviderAdapterManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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

        $result = $adapterManager
            ->for($provider->provider_type)
            ->makeOutboundCall(
                credentials: (array) $provider->credentials_encrypted,
                to: $call->to_number,
                from: $from,
                twimlUrl: $this->twimlUrl,
                statusCallbackUrl: $this->statusCallbackUrl,
            );

        if ($result['ok'] && ! empty($result['provider_call_id'])) {
            $call->provider_call_id = $result['provider_call_id'];
            $call->failure_reason = null;
            $call->save();

            CallEvent::query()->create([
                'tenant_id' => $call->tenant_id,
                'call_session_id' => $call->id,
                'provider_account_id' => $call->provider_account_id,
                'event_type' => 'call.updated',
                'provider_event_type' => 'outbound.dispatched',
                'status_after' => $call->status,
                'payload' => [
                    'provider_call_id' => (string) $result['provider_call_id'],
                    'mode' => (string) ($result['mode'] ?? 'live'),
                ],
                'occurred_at' => now(),
            ]);

            return;
        }

        $this->failCall($call, $result['message'] ?? 'Provider dispatch failed.');
    }

    private function failCall(CallSession $call, string $reason): void
    {
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
}
