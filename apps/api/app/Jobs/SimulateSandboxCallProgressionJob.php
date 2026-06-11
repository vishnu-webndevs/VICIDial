<?php

namespace App\Jobs;

use App\Models\CallEvent;
use App\Models\CallSession;
use App\Services\UsageQuotaService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SimulateSandboxCallProgressionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    /**
     * @param string $callSessionId  The call session to progress.
     * @param string $targetStatus   The status to transition to ('ringing', 'in_progress', or 'completed').
     */
    public function __construct(
        public readonly string $callSessionId,
        public readonly string $targetStatus,
    ) {
    }

    public function handle(UsageQuotaService $usageQuotaService): void
    {
        $call = CallSession::query()->find($this->callSessionId);
        if (! $call instanceof CallSession) {
            return;
        }

        // Skip if the call has already reached a terminal state.
        if (in_array($call->status, ['completed', 'failed', 'busy', 'no_answer', 'timeout', 'rejected', 'canceled'], true)) {
            return;
        }

        $previousStatus = $call->status;

        switch ($this->targetStatus) {
            case 'ringing':
                $call->status = 'ringing';
                $call->runtime_state = 'ringing';
                $call->save();

                CallEvent::query()->create([
                    'tenant_id' => $call->tenant_id,
                    'call_session_id' => $call->id,
                    'provider_account_id' => $call->provider_account_id,
                    'event_type' => 'call.ringing',
                    'provider_event_type' => 'sandbox.simulated',
                    'status_after' => 'ringing',
                    'payload' => ['mode' => 'sandbox', 'previous_status' => $previousStatus],
                    'occurred_at' => now(),
                ]);
                break;

            case 'in_progress':
                $call->status = 'in_progress';
                $call->runtime_state = 'connected';
                if (! $call->started_at) {
                    $call->started_at = now();
                }
                $call->save();

                CallEvent::query()->create([
                    'tenant_id' => $call->tenant_id,
                    'call_session_id' => $call->id,
                    'provider_account_id' => $call->provider_account_id,
                    'event_type' => 'call.answered',
                    'provider_event_type' => 'sandbox.simulated',
                    'status_after' => 'in_progress',
                    'payload' => ['mode' => 'sandbox', 'previous_status' => $previousStatus],
                    'occurred_at' => now(),
                ]);
                break;

            case 'completed':
                $call->status = 'completed';
                $call->runtime_state = 'completed';
                $call->ended_at = now();

                // Calculate duration from started_at if available.
                if ($call->started_at) {
                    $call->duration_seconds = (int) $call->started_at->diffInSeconds($call->ended_at);
                } else {
                    $call->duration_seconds = 0;
                }

                $call->save();

                CallEvent::query()->create([
                    'tenant_id' => $call->tenant_id,
                    'call_session_id' => $call->id,
                    'provider_account_id' => $call->provider_account_id,
                    'event_type' => 'call.completed',
                    'provider_event_type' => 'sandbox.simulated',
                    'status_after' => 'completed',
                    'payload' => [
                        'mode' => 'sandbox',
                        'previous_status' => $previousStatus,
                        'duration_seconds' => $call->duration_seconds,
                    ],
                    'occurred_at' => now(),
                ]);

                // Consume usage quota for call minutes.
                if ($call->duration_seconds > 0) {
                    $minutes = (int) ceil($call->duration_seconds / 60);
                    $usageQuotaService->consume($call->tenant_id, 'call_minutes', $minutes, 'call_session', $call->id);
                }
                break;
        }
    }

    /**
     * Dispatch the full sandbox call progression chain for a given call session.
     *
     * Schedules three jobs with increasing delays:
     *   - ringing after $ringingDelay seconds
     *   - in_progress after $answeredDelay seconds
     *   - completed after $completedDelay seconds
     */
    public static function dispatchProgression(
        string $callSessionId,
        int $ringingDelay = 1,
        int $answeredDelay = 3,
        int $completedDelay = 15,
    ): void {
        self::dispatch($callSessionId, 'ringing')->delay(now()->addSeconds($ringingDelay));
        self::dispatch($callSessionId, 'in_progress')->delay(now()->addSeconds($answeredDelay));
        self::dispatch($callSessionId, 'completed')->delay(now()->addSeconds($completedDelay));
    }
}
