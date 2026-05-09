<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AgentSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentSessionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $sessions = AgentSession::query()
            ->with('agent:id,company_number,status')
            ->where('tenant_id', $tenant->id)
            ->whereNotNull('agent_id')
            ->orderByRaw("CASE WHEN status = 'available' THEN 0 WHEN status = 'busy' THEN 1 WHEN status = 'on_break' THEN 2 ELSE 3 END")
            ->orderByDesc('last_heartbeat_at')
            ->paginate((int) $request->integer('per_page', 25));

        return response()->json([
            'data' => collect($sessions->items())->map(fn (AgentSession $session) => $this->serializeSession($session))->values(),
            'meta' => [
                'pagination' => [
                    'total' => $sessions->total(),
                    'per_page' => $sessions->perPage(),
                    'current_page' => $sessions->currentPage(),
                    'last_page' => $sessions->lastPage(),
                ],
            ],
        ]);
    }

    public function upsert(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'agent_id' => ['required', 'uuid'],
            'status' => ['required', 'in:offline,available,busy,on_break'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:5'],
        ]);

        Agent::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $validated['agent_id'])
            ->firstOrFail();

        $session = AgentSession::query()->updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'agent_id' => $validated['agent_id'],
            ],
            [
                'status' => $validated['status'],
                'capacity' => (int) ($validated['capacity'] ?? 1),
                'available_since' => $validated['status'] === 'available' ? now() : null,
                'last_heartbeat_at' => now(),
            ]
        );

        $session->loadMissing('agent:id,company_number,status');

        return response()->json(['data' => $this->serializeSession($session)]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'paused' => ['required', 'boolean'],
            'pause_reason' => ['nullable', 'string', 'max:120'],
        ]);

        $session = AgentSession::query()
            ->with('agent:id,company_number,status')
            ->where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        $metadata = is_array($session->metadata) ? $session->metadata : [];
        if ($validated['paused']) {
            $metadata['pause_reason'] = $validated['pause_reason'] ?? 'manual';
        } else {
            unset($metadata['pause_reason']);
        }

        $session->status = $validated['paused'] ? 'on_break' : 'available';
        $session->metadata = $metadata;
        $session->last_heartbeat_at = now();
        if ($session->status === 'available') {
            $session->available_since = now();
        }
        $session->save();

        return response()->json(['data' => $this->serializeSession($session)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSession(AgentSession $session): array
    {
        $name = (string) ($session->agent?->company_number ?? '');
        $status = in_array($session->status, ['available', 'busy', 'on_break'], true) ? 'online' : 'offline';

        return [
            'id' => $session->id,
            'agent_id' => $session->agent_id,
            'name' => $name,
            'agent_name' => $name,
            'status' => $status,
            'paused' => $session->status === 'on_break',
            'calls_handled' => (int) $session->active_assignments,
            'capacity' => (int) $session->capacity,
            'active_assignments' => (int) $session->active_assignments,
            'last_active_at' => $session->last_heartbeat_at?->toISOString(),
            'last_heartbeat_at' => $session->last_heartbeat_at?->toISOString(),
            'session_status' => $session->status,
        ];
    }
}
