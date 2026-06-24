<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AgentPhoneAssignment;
use App\Models\Campaign;
use App\Models\CampaignAgentAssignment;
use App\Models\ProviderAccount;
use App\Models\ProviderPhoneNumber;
use App\Services\AuditLogger;
use App\Services\Providers\ProviderAdapterManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\RateLimiter;

class AdminCommunicationSettingsController extends Controller
{
    public function __construct(
        private readonly ProviderAdapterManager $adapterManager,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $providers = ProviderAccount::query()
            ->with(['phoneNumbers' => fn($q) => $q->orderBy('phone_number')])
            ->where('tenant_id', $tenant->id)
            ->latest('created_at')
            ->get()
            ->map(fn(ProviderAccount $provider) => [
                'id' => $provider->id,
                'provider_type' => $provider->provider_type,
                'display_name' => $provider->display_name,
                'status' => $provider->status,
                'last_tested_at' => $provider->last_tested_at?->toISOString(),
                'last_error_code' => $provider->last_error_code,
                'last_error_message' => $provider->last_error_message,
                'numbers' => $provider->phoneNumbers->map(fn(ProviderPhoneNumber $number) => $this->serializeNumber($number))->values(),
                'credentials' => collect((array) $provider->credentials_encrypted)
                    ->map(fn($val, $key) => in_array($key, ['auth_token', 'twilio_api_key_secret', 'api_secret'], true) ? '••••••••••••••••' : $val)
                    ->all(),
            ])
            ->values();

        return response()->json(['data' => ['providers' => $providers]]);
    }

    public function fetchProviderNumbers(Request $request, string $providerId): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $provider = $this->resolveProvider($tenant->id, $providerId);
        $this->checkTwilioRateLimit($tenant->id, $provider->id, 'fetch');

        $numbers = $this->adapterManager
            ->for($provider->provider_type)
            ->fetchIncomingPhoneNumbers((array) $provider->credentials_encrypted);

        return response()->json([
            'data' => [
                'provider_id' => $provider->id,
                'provider_type' => $provider->provider_type,
                'numbers' => $numbers,
            ],
        ]);
    }

    public function syncProviderNumbers(Request $request, string $providerId): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $provider = $this->resolveProvider($tenant->id, $providerId);
        $validated = $request->validate([
            'numbers' => ['required', 'array', 'min:1'],
            'numbers.*.phone_number' => ['required', 'regex:/^\+[1-9]\d{7,14}$/'],
            'numbers.*.friendly_name' => ['nullable', 'string', 'max:120'],
            'numbers.*.sid' => ['nullable', 'string', 'max:64'],
            'numbers.*.capabilities' => ['nullable', 'array'],
        ]);

        $autoValidate = $provider->provider_type === 'twilio';
        $status = $autoValidate ? 'active' : 'inactive';
        $isValidated = $autoValidate;

        $upserts = [];
        foreach ($validated['numbers'] as $item) {
            $upserts[] = [
                'tenant_id' => $tenant->id,
                'provider_account_id' => $provider->id,
                'provider_number_sid' => $item['sid'] ?? null,
                'phone_number' => $item['phone_number'],
                'friendly_name' => $item['friendly_name'] ?? null,
                'status' => $status,
                'is_validated' => $isValidated,
                'last_tested_at' => $autoValidate ? now() : null,
                'last_error_code' => null,
                'last_error_message' => null,
                'capabilities' => json_encode($item['capabilities'] ?? []),
                'updated_at' => now(),
                'created_at' => now(),
            ];
        }

        ProviderPhoneNumber::query()->upsert(
            $upserts,
            ['provider_account_id', 'phone_number'],
            [
                'provider_number_sid',
                'friendly_name',
                'capabilities',
                'status',
                'is_validated',
                'last_tested_at',
                'last_error_code',
                'last_error_message',
                'updated_at',
            ]
        );

        $stored = ProviderPhoneNumber::query()
            ->where('tenant_id', $tenant->id)
            ->where('provider_account_id', $provider->id)
            ->orderBy('phone_number')
            ->get()
            ->map(fn(ProviderPhoneNumber $number) => $this->serializeNumber($number))
            ->values();

        $this->auditLogger->log(
            action: 'provider.numbers_synced',
            resourceType: 'provider_account',
            resourceId: $provider->id,
            tenantId: $tenant->id,
            actorId: $request->user()?->id,
            newValues: ['numbers_count' => count($validated['numbers'])],
            request: $request
        );

        return response()->json(['data' => ['provider_id' => $provider->id, 'numbers' => $stored]]);
    }

    public function testProviderAndNumber(Request $request, string $providerId): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $provider = $this->resolveProvider($tenant->id, $providerId);
        $validated = $request->validate([
            'provider_phone_number_id' => ['nullable', 'uuid'],
        ]);

        $this->checkTwilioRateLimit($tenant->id, $provider->id, 'test');

        $providerResult = $this->adapterManager
            ->for($provider->provider_type)
            ->testConnection((array) $provider->credentials_encrypted);

        $provider->last_tested_at = now();
        if ($providerResult['ok'] === true) {
            $provider->status = 'active';
            $provider->last_error_code = null;
            $provider->last_error_message = null;
        } else {
            $provider->status = 'error';
            $provider->last_error_code = (string) ($providerResult['code'] ?? 'PROVIDER_TEST_FAILED');
            $provider->last_error_message = (string) ($providerResult['message'] ?? 'Provider connection test failed.');
        }
        $provider->save();

        $numberResult = null;
        if (!empty($validated['provider_phone_number_id'])) {
            $number = ProviderPhoneNumber::query()
                ->where('tenant_id', $tenant->id)
                ->where('provider_account_id', $provider->id)
                ->where('id', $validated['provider_phone_number_id'])
                ->firstOrFail();

            $numberResult = $this->adapterManager
                ->for($provider->provider_type)
                ->validateNumberOwnership((array) $provider->credentials_encrypted, $number->phone_number);

            $number->last_tested_at = now();
            $number->is_validated = (bool) ($numberResult['ok'] ?? false);
            $number->status = $number->is_validated ? 'active' : 'error';
            $number->last_error_code = $number->is_validated ? null : (string) ($numberResult['code'] ?? 'NUMBER_TEST_FAILED');
            $number->last_error_message = $number->is_validated ? null : (string) ($numberResult['message'] ?? 'Number validation failed.');
            $number->save();
        }

        $this->auditLogger->log(
            action: 'provider.connection_tested',
            resourceType: 'provider_account',
            resourceId: $provider->id,
            tenantId: $tenant->id,
            actorId: $request->user()?->id,
            newValues: [
                'provider_ok' => (bool) ($providerResult['ok'] ?? false),
                'number_tested' => !empty($validated['provider_phone_number_id']),
            ],
            request: $request
        );

        return response()->json([
            'data' => [
                'provider' => [
                    'id' => $provider->id,
                    'status' => $provider->status,
                    'last_tested_at' => $provider->last_tested_at?->toISOString(),
                    'last_error_code' => $provider->last_error_code,
                    'last_error_message' => $provider->last_error_message,
                ],
                'provider_test_result' => $providerResult,
                'number_test_result' => $numberResult,
            ],
        ]);
    }

    public function assignAgentNumber(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'agent_id' => ['required', 'uuid'],
            'provider_phone_number_id' => ['required', 'uuid'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        $agent = $this->resolveAgent($tenant->id, $validated['agent_id']);
        $number = ProviderPhoneNumber::query()
            ->with('providerAccount')
            ->where('tenant_id', $tenant->id)
            ->where('id', $validated['provider_phone_number_id'])
            ->firstOrFail();

        if (!$number->is_validated) {
            $provider = $number->providerAccount;
            if (!$provider) {
                return response()->json([
                    'error' => [
                        'code' => 'PROVIDER_NOT_FOUND',
                        'message' => 'Provider account for selected number was not found.',
                    ],
                ], 422);
            }

            $validation = $this->adapterManager
                ->for($provider->provider_type)
                ->validateNumberOwnership((array) $provider->credentials_encrypted, (string) $number->phone_number);

            if (($validation['ok'] ?? false) !== true) {
                return response()->json([
                    'error' => [
                        'code' => (string) ($validation['code'] ?? 'NUMBER_NOT_VALIDATED'),
                        'message' => (string) ($validation['message'] ?? 'Number validation failed.'),
                    ],
                ], 422);
            }

            $number->is_validated = true;
            $number->status = 'active';
            $number->save();
        }

        if ($number->status !== 'active') {
            $number->status = 'active';
            $number->save();
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

        return response()->json(['data' => $this->serializeAgentAssignment($assignment->fresh(['agent', 'number']))], 201);
    }

    public function listAgentNumberAssignments(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $rows = AgentPhoneAssignment::query()
            ->with(['agent', 'number'])
            ->where('tenant_id', $tenant->id)
            ->latest('updated_at')
            ->get()
            ->map(fn(AgentPhoneAssignment $row) => $this->serializeAgentAssignment($row))
            ->values();

        return response()->json(['data' => $rows]);
    }

    public function listValidatedNumbers(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $numbers = ProviderPhoneNumber::query()
            ->with('providerAccount:id,display_name,provider_type')
            ->where('tenant_id', $tenant->id)
            ->where('is_validated', true)
            ->where('status', 'active')
            ->orderBy('phone_number')
            ->get()
            ->map(fn(ProviderPhoneNumber $number) => $this->serializeNumber($number))
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

            if (!$numberId) {
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
            ->orderBy('created_at')
            ->get()
            ->map(fn(CampaignAgentAssignment $row) => [
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

    private function resolveProvider(string $tenantId, string $providerId): ProviderAccount
    {
        return ProviderAccount::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $providerId)
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

    private function resolveAgent(string $tenantId, string $agentId): Agent
    {
        return Agent::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $agentId)
            ->firstOrFail();
    }

    private function checkTwilioRateLimit(string $tenantId, string $providerId, string $action): void
    {
        $key = "twilio:{$tenantId}:{$providerId}:{$action}";
        $maxAttempts = 30;
        $decaySeconds = 60;

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            throw new HttpResponseException(response()->json([
                'error' => [
                    'code' => 'TWILIO_RATE_LIMITED',
                    'message' => 'Twilio API rate limit reached. Please retry in a moment.',
                ],
            ], 429));
        }

        RateLimiter::hit($key, $decaySeconds);
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
            'capabilities' => $number->capabilities ?? [],
            'last_tested_at' => $number->last_tested_at?->toISOString(),
            'last_error_code' => $number->last_error_code,
            'last_error_message' => $number->last_error_message,
            'provider' => $number->relationLoaded('providerAccount') && $number->providerAccount
                ? [
                    'id' => $number->providerAccount->id,
                    'display_name' => $number->providerAccount->display_name,
                    'provider_type' => $number->providerAccount->provider_type,
                ]
                : null,
        ];
    }

    private function serializeAgentAssignment(AgentPhoneAssignment $assignment): array
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
