<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DialerLoopIncident;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DialerLoopIncidentController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $validated = $request->validate([
            'timestamp' => ['required', 'date'],
            'session_id' => ['required', 'string', 'max:100'],
            'loop_signature' => ['required', 'string', 'max:190'],
            'browser' => ['required', 'array'],
            'browser.user_agent' => ['required', 'string', 'max:1000'],
            'browser.platform' => ['nullable', 'string', 'max:120'],
            'browser.language' => ['nullable', 'string', 'max:20'],
            'error_stack_trace' => ['nullable', 'string', 'max:20000'],
            'actions' => ['required', 'array', 'min:1', 'max:100'],
            'actions.*.at' => ['required', 'date'],
            'actions.*.type' => ['required', 'string', 'max:120'],
            'actions.*.details' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
        ]);

        $incident = DialerLoopIncident::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $request->user()?->id,
            'session_id' => (string) $validated['session_id'],
            'request_id' => (string) $request->attributes->get('request_id', ''),
            'loop_signature' => (string) $validated['loop_signature'],
            'occurred_at' => (string) $validated['timestamp'],
            'browser' => (array) $validated['browser'],
            'stack_trace' => isset($validated['error_stack_trace']) ? (string) $validated['error_stack_trace'] : null,
            'actions' => (array) $validated['actions'],
            'metadata' => (array) ($validated['metadata'] ?? []),
        ]);

        $context = [
            'incident_id' => $incident->id,
            'tenant_id' => $tenant->id,
            'user_id' => $request->user()?->id,
            'session_id' => $incident->session_id,
            'loop_signature' => $incident->loop_signature,
            'occurred_at' => $incident->occurred_at?->toISOString(),
            'action_count' => count((array) $incident->actions),
            'browser' => $incident->browser,
            'request_id' => $incident->request_id,
            'metadata' => $incident->metadata,
        ];

        Log::channel('dialer_incidents')->error('dialer.loop_detected', $context + [
            'actions' => $incident->actions,
            'stack_trace' => $incident->stack_trace,
        ]);

        Log::channel('dialer_incident_alerts')->critical('dialer.loop_detected.alert', $context);

        return response()->json([
            'success' => true,
            'data' => [
                'incident_id' => $incident->id,
                'logged_at' => now()->toISOString(),
            ],
        ], 201);
    }
}
