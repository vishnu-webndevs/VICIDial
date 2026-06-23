<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AgentPhoneAssignment;
use App\Models\AgentSession;
use App\Models\Campaign;
use App\Models\CampaignAgentAssignment;
use App\Models\ProviderAccount;
use App\Models\ProviderPhoneNumber;
use App\Services\AuditLogger;
use App\Services\Providers\ProviderAdapterManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AgentController extends Controller
{
    public function __construct(
        private readonly ProviderAdapterManager $adapterManager,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $agents = Agent::query()
            ->with(['phoneAssignments.number', 'sessions'])
            ->where('tenant_id', $tenant->id)
            ->orderBy('company_number')
            ->get()
            ->map(fn (Agent $agent) => $this->serializeAgent($agent))
            ->values();

        return response()->json(['data' => $agents]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'company_number' => ['required', 'string', 'min:1', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'status' => ['nullable', 'in:active,inactive'],
            'destination_number' => ['nullable', 'regex:/^\+[1-9]\d{7,14}$/'],
        ]);

        $agent = DB::transaction(function () use ($request, $tenant, $validated): Agent {
            $created = Agent::query()->create([
                'tenant_id' => $tenant->id,
                'company_number' => $validated['company_number'],
                'status' => $validated['status'] ?? 'active',
                'metadata' => ($validated['destination_number'] ?? null) ? ['destination_number' => $validated['destination_number']] : null,
                'created_by' => $request->user()?->id,
            ]);

            AgentSession::query()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'agent_id' => $created->id],
                [
                    'status' => $created->status === 'active' ? 'available' : 'offline',
                    'capacity' => 1,
                    'active_assignments' => 0,
                    'available_since' => now(),
                    'last_heartbeat_at' => now(),
                ]
            );

            return $created;
        });

        $this->auditLogger->log(
            action: 'agent.created',
            resourceType: 'agent',
            resourceId: $agent->id,
            tenantId: $tenant->id,
            actorId: $request->user()?->id,
            newValues: ['company_number' => $agent->company_number, 'status' => $agent->status],
            request: $request
        );

        return response()->json(['data' => $this->serializeAgent($agent->fresh(['phoneAssignments.number', 'sessions']))], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $agent = Agent::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        $validated = $request->validate([
            'company_number' => ['sometimes', 'string', 'min:1', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'status' => ['sometimes', 'in:active,inactive'],
            'destination_number' => ['sometimes', 'nullable', 'regex:/^\+[1-9]\d{7,14}$/'],
            'calling_method' => ['sometimes', 'in:webrtc,phone'],
        ]);

        if ($validated === []) {
            return response()->json(['data' => $this->serializeAgent($agent->fresh(['phoneAssignments.number', 'sessions']))]);
        }

        $agent->fill($validated);
        $metadata = (array) ($agent->metadata ?? []);
        $metadataUpdated = false;

        if (array_key_exists('destination_number', $validated)) {
            $destination = $validated['destination_number'] ?? null;
            if (is_string($destination) && trim($destination) !== '') {
                $metadata['destination_number'] = trim($destination);
            } else {
                unset($metadata['destination_number']);
            }
            $metadataUpdated = true;
        }

        if (array_key_exists('calling_method', $validated)) {
            $metadata['calling_method'] = $validated['calling_method'];
            $metadataUpdated = true;
        }

        if ($metadataUpdated) {
            $agent->metadata = $metadata === [] ? null : $metadata;
        }
        $agent->save();

        if (array_key_exists('status', $validated)) {
            AgentSession::query()
                ->where('tenant_id', $tenant->id)
                ->where('agent_id', $agent->id)
                ->update([
                    'status' => $agent->status === 'active' ? 'available' : 'offline',
                    'last_heartbeat_at' => now(),
                ]);
        }

        $this->auditLogger->log(
            action: 'agent.updated',
            resourceType: 'agent',
            resourceId: $agent->id,
            tenantId: $tenant->id,
            actorId: $request->user()?->id,
            newValues: $validated,
            request: $request
        );

        return response()->json(['data' => $this->serializeAgent($agent->fresh(['phoneAssignments.number', 'sessions']))]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $agent = Agent::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        $agent->delete();

        $this->auditLogger->log(
            action: 'agent.deleted',
            resourceType: 'agent',
            resourceId: $id,
            tenantId: $tenant->id,
            actorId: $request->user()?->id,
            oldValues: ['company_number' => $agent->company_number],
            request: $request
        );

        return response()->json(['data' => ['id' => $id, 'deleted' => true]]);
    }

    public function assignNumber(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'agent_id' => ['required', 'uuid'],
            'provider_account_id' => ['nullable', 'uuid'],
            'provider_phone_number_id' => ['required', 'uuid'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        $agent = $this->resolveAgent($tenant->id, $validated['agent_id']);
        $number = ProviderPhoneNumber::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $validated['provider_phone_number_id'])
            ->firstOrFail();

        $providerAccountId = $validated['provider_account_id'] ?? $number->provider_account_id;
        $provider = ProviderAccount::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $providerAccountId)
            ->where('status', 'active')
            ->first();
        if (! $provider) {
            return response()->json([
                'error' => [
                    'code' => 'INVALID_PROVIDER',
                    'message' => 'Selected provider is invalid or inactive for this tenant.',
                ],
            ], 422);
        }
        if ($number->provider_account_id !== $provider->id) {
            return response()->json([
                'error' => [
                    'code' => 'PROVIDER_NUMBER_MISMATCH',
                    'message' => 'Selected number does not belong to the selected provider.',
                ],
            ], 422);
        }

        if (! $number->is_validated || $number->status !== 'active') {
            $validation = $this->adapterManager
                ->for($provider->provider_type)
                ->validateNumberOwnership((array) $provider->credentials_encrypted, (string) $number->phone_number);

            $number->last_tested_at = now();
            $number->is_validated = (bool) ($validation['ok'] ?? false);
            $number->status = $number->is_validated ? 'active' : 'error';
            $number->last_error_code = $number->is_validated ? null : (string) ($validation['code'] ?? 'NUMBER_TEST_FAILED');
            $number->last_error_message = $number->is_validated ? null : (string) ($validation['message'] ?? 'Number validation failed.');
            $number->save();

            if (! $number->is_validated) {
                return response()->json([
                    'error' => [
                        'code' => (string) ($validation['code'] ?? 'NUMBER_NOT_VALIDATED'),
                        'message' => (string) ($validation['message'] ?? 'Number is not validated.'),
                    ],
                ], 422);
            }
        }

        $assignment = AgentPhoneAssignment::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'agent_id' => $agent->id],
            [
                'provider_phone_number_id' => $number->id,
                'status' => $validated['status'] ?? 'active',
                'assigned_by' => $request->user()?->id,
                'assigned_at' => now(),
            ]
        );

        $this->auditLogger->log(
            action: 'agent.number_assigned',
            resourceType: 'agent_phone_assignment',
            resourceId: $assignment->id,
            tenantId: $tenant->id,
            actorId: $request->user()?->id,
            newValues: ['agent_id' => $agent->id, 'phone_number' => $number->phone_number],
            request: $request
        );

        return response()->json(['data' => $this->serializeNumberAssignment($assignment->fresh(['agent', 'number']))], 201);
    }

    public function listNumberAssignments(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $rows = AgentPhoneAssignment::query()
            ->with(['agent', 'number'])
            ->where('tenant_id', $tenant->id)
            ->whereNotNull('agent_id')
            ->latest('updated_at')
            ->get()
            ->map(fn (AgentPhoneAssignment $row) => $this->serializeNumberAssignment($row))
            ->values();

        return response()->json(['data' => $rows]);
    }

    public function listCampaignAgents(Request $request, string $campaignId): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $campaign = Campaign::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $campaignId)
            ->firstOrFail();

        $rows = CampaignAgentAssignment::query()
            ->with(['agent', 'number'])
            ->where('tenant_id', $tenant->id)
            ->where('campaign_id', $campaign->id)
            ->whereNotNull('agent_id')
            ->orderBy('created_at')
            ->get()
            ->map(fn (CampaignAgentAssignment $row) => [
                'id' => $row->id,
                'campaign_id' => $row->campaign_id,
                'agent' => $row->agent ? [
                    'id' => $row->agent->id,
                    'company_number' => $row->agent->company_number,
                    'status' => $row->agent->status,
                ] : null,
                'number' => $row->number ? $this->serializeNumber($row->number) : null,
            ])
            ->values();

        return response()->json(['data' => $rows]);
    }

    public function listValidatedNumbers(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'provider_account_id' => ['nullable', 'uuid'],
            'provider_type' => ['nullable', 'in:twilio,vonage'],
        ]);

        $numbers = ProviderPhoneNumber::query()
            ->where('tenant_id', $tenant->id)
            ->where('is_validated', true)
            ->where('status', 'active')
            ->when(
                ! empty($validated['provider_account_id']),
                fn ($query) => $query->where('provider_account_id', $validated['provider_account_id'])
            )
            ->when(
                ! empty($validated['provider_type']),
                fn ($query) => $query->whereHas(
                    'providerAccount',
                    fn ($providerQuery) => $providerQuery
                        ->where('tenant_id', $tenant->id)
                        ->where('provider_type', $validated['provider_type'])
                        ->where('status', 'active')
                )
            )
            ->orderBy('phone_number')
            ->get()
            ->map(fn (ProviderPhoneNumber $number) => $this->serializeNumber($number))
            ->values();

        return response()->json(['data' => $numbers]);
    }

    public function mapCampaignAgents(Request $request, string $campaignId): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $campaign = Campaign::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $campaignId)
            ->firstOrFail();

        $validated = $request->validate([
            'assignments' => ['required', 'array', 'min:1'],
            'assignments.*.agent_id' => ['required', 'uuid'],
            'assignments.*.provider_phone_number_id' => ['nullable', 'uuid'],
        ]);

        foreach ($validated['assignments'] as $entry) {
            $agent = $this->resolveAgent($tenant->id, $entry['agent_id']);
            $numberId = $entry['provider_phone_number_id'] ?? AgentPhoneAssignment::query()
                ->where('tenant_id', $tenant->id)
                ->where('agent_id', $agent->id)
                ->value('provider_phone_number_id');

            if (! $numberId) {
                return response()->json([
                    'error' => [
                        'code' => 'AGENT_NUMBER_NOT_ASSIGNED',
                        'message' => 'Each campaign agent must have an assigned validated number.',
                    ],
                ], 422);
            }
            $this->resolveValidatedTenantNumber($tenant->id, $numberId);

            CampaignAgentAssignment::query()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'campaign_id' => $campaign->id, 'agent_id' => $agent->id],
                ['provider_phone_number_id' => $numberId, 'created_by' => $request->user()?->id]
            );
        }

        $this->auditLogger->log(
            action: 'campaign.agent_mapping_updated',
            resourceType: 'campaign',
            resourceId: $campaign->id,
            tenantId: $tenant->id,
            actorId: $request->user()?->id,
            newValues: ['assignments_count' => count($validated['assignments'])],
            request: $request
        );

        return $this->listCampaignAgents($request, $campaignId);
    }

    private function resolveAgent(string $tenantId, string $agentId): Agent
    {
        return Agent::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $agentId)
            ->firstOrFail();
    }

    private function resolveValidatedTenantNumber(string $tenantId, string $numberId): ProviderPhoneNumber
    {
        return ProviderPhoneNumber::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $numberId)
            ->where('is_validated', true)
            ->where('status', 'active')
            ->firstOrFail();
    }

    private function serializeAgent(Agent $agent): array
    {
        $assignment = $agent->phoneAssignments->first();
        $session = $agent->sessions->first();
        $metadata = (array) ($agent->metadata ?? []);

        return [
            'id' => $agent->id,
            'company_number' => $agent->company_number,
            'status' => $agent->status,
            'created_at' => $agent->created_at?->toISOString(),
            'destination_number' => $metadata['destination_number'] ?? null,
            'calling_method' => $metadata['calling_method'] ?? 'phone',
            'default_number' => $assignment?->number ? $this->serializeNumber($assignment->number) : null,
            'session' => $session ? [
                'id' => $session->id,
                'status' => $session->status,
                'capacity' => (int) $session->capacity,
                'active_assignments' => (int) $session->active_assignments,
                'last_heartbeat_at' => $session->last_heartbeat_at?->toISOString(),
            ] : null,
        ];
    }

    private function serializeNumber(ProviderPhoneNumber $number): array
    {
        return [
            'id' => $number->id,
            'provider_account_id' => $number->provider_account_id,
            'phone_number' => $number->phone_number,
            'friendly_name' => $number->friendly_name,
            'status' => $number->status,
            'is_validated' => (bool) $number->is_validated,
        ];
    }

    private function serializeNumberAssignment(AgentPhoneAssignment $assignment): array
    {
        return [
            'id' => $assignment->id,
            'status' => $assignment->status,
            'assigned_at' => $assignment->assigned_at?->toISOString(),
            'agent' => $assignment->agent ? [
                'id' => $assignment->agent->id,
                'company_number' => $assignment->agent->company_number,
                'status' => $assignment->agent->status,
            ] : null,
            'number' => $assignment->number ? $this->serializeNumber($assignment->number) : null,
        ];
    }
}
