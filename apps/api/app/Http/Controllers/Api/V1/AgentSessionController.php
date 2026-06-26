<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AgentSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

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

        // #region debug-point D:agent-heartbeat
        $this->debugReport('D', 'agent.session.upsert.request', [
            'tenant_id' => (string) ($tenant?->id ?? ''),
            'agent_id' => (string) $validated['agent_id'],
            'status' => (string) $validated['status'],
            'capacity' => (int) ($validated['capacity'] ?? 1),
        ]);
        // #endregion

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

        try {
            $session->active_assignments = app(\App\Services\Campaigns\CampaignRunnerService::class)
                ->syncAgentSessionActiveAssignments($tenant->id, (string) $session->id);
            if ($session->active_assignments < $session->capacity && $session->status === 'available') {
                $session->available_since = $session->available_since ?: now();
            }
            $session->save();
        } catch (\Throwable) {
        }

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
                'location' => 'AgentSessionController',
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
