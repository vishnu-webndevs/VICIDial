<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CallEvent;
use App\Models\CallSession;
use Illuminate\Http\Request;
use Illuminate\Support\CarbonImmutable;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RealtimeController extends Controller
{
    public function calls(Request $request): StreamedResponse
    {
        $tenant = $request->attributes->get('tenant');
        $cursor = (string) ($request->header('Last-Event-ID') ?: $request->query('cursor', ''));
        [$cursorTime, $cursorId] = $this->parseCursor($cursor);

        return response()->stream(
            function () use ($tenant, $cursorTime, $cursorId): void {
                ignore_user_abort(true);
                set_time_limit(0);
                $loopStarted = microtime(true);
                $maxDurationSeconds = (float) config('realtime.stream.max_duration_seconds', 25);
                $pollIntervalMicroseconds = (int) config('realtime.stream.poll_interval_microseconds', 750000);
                $currentTime = $cursorTime;
                $currentId = $cursorId;

                echo "event: stream.ready\n";
                echo 'data: {"ready":true}'."\n\n";
                @ob_flush();
                @flush();

                while (! connection_aborted() && (microtime(true) - $loopStarted) < $maxDurationSeconds) {
                    $events = CallEvent::query()
                        ->with(['callSession.providerAccount'])
                        ->where('tenant_id', $tenant->id)
                        ->when(
                            $currentId !== null || $currentTime !== null,
                            function ($query) use ($currentTime, $currentId) {
                                if ($currentId !== null) {
                                    // UUIDv7 IDs are monotonic; this avoids timezone precision drift on timestamp comparisons.
                                    $query->where('id', '>', $currentId);

                                    return;
                                }

                                $query->where('created_at', '>', $currentTime);
                            }
                        )
                        ->orderBy('created_at')
                        ->orderBy('id')
                        ->limit(100)
                        ->get();

                    foreach ($events as $event) {
                        $call = $event->callSession;
                        if (! $call instanceof CallSession) {
                            continue;
                        }

                        $currentTime = $event->created_at?->toImmutable();
                        $currentId = $event->id;
                        $cursorTime = $currentTime?->format('Y-m-d\TH:i:s.u\Z');
                        $cursor = ($cursorTime ?? '').'|'.($currentId ?? '');
                        $payload = [
                            'event_id' => $event->id,
                            'event_type' => $event->event_type,
                            'status_after' => $event->status_after,
                            'occurred_at' => $event->occurred_at?->toISOString(),
                            'cursor' => $cursor,
                            'call' => $this->serializeCall($call),
                        ];

                        echo "id: {$cursor}\n";
                        echo "event: call.status.updated\n";
                        echo 'data: '.json_encode($payload, JSON_UNESCAPED_SLASHES)."\n\n";
                        @ob_flush();
                        @flush();
                    }

                    usleep(max(0, $pollIntervalMicroseconds));
                }

                echo "event: stream.closed\n";
                echo 'data: {"reason":"rotate"}'."\n\n";
                @ob_flush();
                @flush();
            },
            200,
            [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache, no-transform',
                'Connection' => 'keep-alive',
                'X-Accel-Buffering' => 'no',
            ]
        );
    }

    private function parseCursor(string $cursor): array
    {
        $decoded = urldecode(trim($cursor));
        if ($decoded === '' || ! str_contains($decoded, '|')) {
            return [null, null];
        }

        [$time, $id] = explode('|', $decoded, 2);
        $time = trim($time);
        $id = trim($id);
        if ($id === '') {
            return [null, null];
        }

        if ($time === '') {
            return [null, $id];
        }

        try {
            $parsedTime = CarbonImmutable::parse($time);
        } catch (\Throwable) {
            return [null, $id];
        }

        return [$parsedTime, $id];
    }

    private function serializeCall(CallSession $call): array
    {
        return [
            'id' => $call->id,
            'direction' => $call->direction,
            'status' => $call->status,
            'provider_call_id' => $call->provider_call_id,
            'from_number' => $call->from_number,
            'to_number' => $call->to_number,
            'duration_seconds' => $call->duration_seconds,
            'retry_count' => $call->retry_count,
            'failure_reason' => $call->failure_reason,
            'provider' => $call->providerAccount ? [
                'id' => $call->providerAccount->id,
                'label' => $call->providerAccount->display_name,
                'type' => $call->providerAccount->provider_type,
            ] : null,
            'started_at' => $call->started_at?->toISOString(),
            'ended_at' => $call->ended_at?->toISOString(),
            'created_at' => $call->created_at?->toISOString(),
            'controls' => array_merge(['muted' => false, 'on_hold' => false], (array) (($call->metadata ?? [])['controls'] ?? [])),
        ];
    }
}
