<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CallEvent;
use App\Models\CallSession;
use App\Models\ProviderAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $perPage = (int) $request->integer('per_page', 25);

        $providerLogs = CallEvent::query()
            ->where('tenant_id', $tenant->id)
            ->whereNotNull('provider_event_type')
            ->when($request->filled('status'), fn ($q) => $q->where('status_after', (string) $request->input('status')))
            ->latest('occurred_at')
            ->limit($perPage)
            ->get()
            ->map(fn (CallEvent $event) => [
                'id' => $event->id,
                'source' => 'provider',
                'event_type' => $event->event_type,
                'provider_event_type' => $event->provider_event_type,
                'status' => $event->status_after,
                'processed_at' => $event->occurred_at?->toISOString(),
                'error_message' => null,
                'payload' => $event->payload,
            ]);

        $logs = $providerLogs
            ->sortByDesc(fn (array $row) => (string) ($row['processed_at'] ?? ''))
            ->take($perPage)
            ->values();

        return response()->json([
            'data' => $logs,
            'meta' => [
                'pagination' => [
                    'total' => $logs->count(),
                    'per_page' => $perPage,
                    'current_page' => 1,
                    'last_page' => 1,
                ],
            ],
        ]);
    }

    public function overview(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $windowStart = now()->subDay();
        $providerFailureStatuses = ['failed', 'busy', 'no_answer', 'timeout', 'rejected', 'canceled'];
        $providerTotal = CallEvent::query()
            ->where('tenant_id', $tenant->id)
            ->whereNotNull('provider_event_type')
            ->where('occurred_at', '>=', $windowStart)
            ->count();
        $providerFailed = CallEvent::query()
            ->where('tenant_id', $tenant->id)
            ->whereNotNull('provider_event_type')
            ->where('occurred_at', '>=', $windowStart)
            ->whereIn('status_after', $providerFailureStatuses)
            ->count();

        $activeProviders = ProviderAccount::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->selectRaw('provider_type, COUNT(*) as total')
            ->groupBy('provider_type')
            ->get()
            ->mapWithKeys(fn ($row) => [(string) $row->provider_type => (int) $row->total]);

        $recentFailedProviderEvents = CallEvent::query()
            ->where('tenant_id', $tenant->id)
            ->whereNotNull('provider_event_type')
            ->whereIn('status_after', $providerFailureStatuses)
            ->latest('occurred_at')
            ->limit(5)
            ->get()
            ->map(fn (CallEvent $event) => [
                'id' => $event->id,
                'source' => 'provider',
                'event_type' => $event->event_type,
                'status' => $event->status_after,
                'error_message' => data_get($event->payload, 'error_message'),
                'occurred_at' => $event->occurred_at?->toISOString(),
            ]);

        $recentFailures = $recentFailedProviderEvents
            ->sortByDesc(fn (array $row) => (string) ($row['occurred_at'] ?? ''))
            ->take(10)
            ->values();

        return response()->json([
            'data' => [
                'callback_urls' => [
                    'twilio' => url('/api/webhooks/twilio'),
                    'vonage' => url('/api/webhooks/vonage'),
                ],
                'active_provider_accounts' => [
                    'twilio' => (int) ($activeProviders->get('twilio') ?? 0),
                    'vonage' => (int) ($activeProviders->get('vonage') ?? 0),
                ],
                'window_hours' => 24,
                'metrics' => [
                    'provider_total' => $providerTotal,
                    'provider_failed' => $providerFailed,
                    'provider_failure_rate' => $providerTotal > 0 ? round(($providerFailed / $providerTotal) * 100, 2) : 0,
                ],
                'recent_failures' => $recentFailures,
            ],
        ]);
    }

    public function replay(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'source' => ['required', 'in:provider'],
            'id' => ['required', 'uuid'],
        ]);

        $event = CallEvent::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $validated['id'])
            ->whereNotNull('provider_event_type')
            ->firstOrFail();

        $call = CallSession::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $event->call_session_id)
            ->firstOrFail();

        $call->status = (string) $event->status_after;
        if (in_array($call->status, ['completed', 'failed', 'busy', 'no_answer', 'timeout', 'rejected', 'canceled'], true)) {
            $call->ended_at = $call->ended_at ?: now();
            if ($call->status !== 'completed' && ! $call->failure_reason) {
                $call->failure_reason = (string) $event->provider_event_type;
            }
        }
        $call->save();

        CallEvent::query()->create([
            'tenant_id' => $event->tenant_id,
            'call_session_id' => $event->call_session_id,
            'provider_account_id' => $event->provider_account_id,
            'event_type' => 'webhook.replay',
            'provider_event_type' => $event->provider_event_type,
            'status_after' => $event->status_after,
            'payload' => $event->payload,
            'occurred_at' => now(),
        ]);

        return response()->json([
            'data' => [
                'queued' => false,
                'source' => 'provider',
                'id' => $event->id,
                'replayed_at' => now()->toISOString(),
            ],
        ], 202);
    }
}
