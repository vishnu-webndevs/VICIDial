<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AgentPhoneAssignment;
use App\Models\CallAiArtifact;
use App\Models\CallEvent;
use App\Models\CallSession;
use App\Models\ProviderAccount;
use App\Models\ProviderPhoneNumber;
use App\Jobs\DispatchOutboundCallJob;
use App\Jobs\ProcessCallAiArtifactJob;
use App\Services\AuditLogger;
use App\Services\IdempotencyService;
use App\Services\Providers\ProviderAdapterManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CallController extends Controller
{
    private const TERMINAL_STATUSES = ['completed', 'failed', 'busy', 'no_answer', 'timeout', 'rejected', 'canceled'];

    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly IdempotencyService $idempotencyService,
    )
    {
    }

    public function store(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $idempotency = $this->idempotencyService->begin($request, $tenant?->id, $request->user()?->id);
        if (($idempotency['conflict'] ?? false) === true) {
            return response()->json([
                'error' => [
                    'code' => 'IDEMPOTENCY_CONFLICT',
                    'message' => 'Idempotency key has already been used with a different request payload.',
                ],
            ], 409);
        }
        if (($idempotency['in_progress'] ?? false) === true) {
            return response()->json([
                'error' => [
                    'code' => 'IDEMPOTENCY_IN_PROGRESS',
                    'message' => 'Request with this idempotency key is still processing.',
                ],
            ], 409);
        }
        if (($idempotency['replay'] ?? false) === true) {
            return response()
                ->json($idempotency['record']->response_body ?? [], (int) $idempotency['record']->response_status)
                ->header('X-Idempotent-Replay', 'true');
        }

        $validated = $request->validate([
            'to' => ['required', 'regex:/^\+[1-9]\d{7,14}$/'],
            'agent_id' => ['nullable', 'uuid'],
            'from' => ['nullable', 'regex:/^\+[1-9]\d{7,14}$/'],
            'provider_account_id' => ['nullable', 'uuid'],
            'metadata' => ['nullable', 'array', 'max:10'],
        ]);

        $requestMetadata = (array) ($validated['metadata'] ?? []);
        if (! array_key_exists('dial_mode', $requestMetadata) || ! is_string($requestMetadata['dial_mode']) || $requestMetadata['dial_mode'] === '') {
            $requestMetadata['dial_mode'] = 'normal';
        }

        $provider = null;
        $fromNumber = null;
        if (! empty($validated['agent_id'])) {
            $agentIdentity = $this->resolveAgentOutboundIdentity($tenant->id, $validated['agent_id']);
            if (! $agentIdentity) {
                return response()->json([
                    'error' => [
                        'code' => 'AGENT_NUMBER_NOT_ASSIGNED',
                        'message' => 'Selected agent must have an active assigned validated number.',
                    ],
                ], 422);
            }

            $provider = $agentIdentity['provider'];
            $fromNumber = $agentIdentity['from_number'];
        } else {
            $providerQuery = ProviderAccount::query()
                ->where('tenant_id', $tenant->id)
                ->where('status', 'active');

            if (! empty($validated['provider_account_id'])) {
                $providerQuery->where('id', $validated['provider_account_id']);
            }

            $provider = $providerQuery->latest('created_at')->first();
            if (! $provider) {
                return response()->json([
                    'error' => [
                        'code' => 'PROVIDER_NOT_CONFIGURED',
                        'message' => 'No active provider account is available for this tenant.',
                    ],
                ], 422);
            }

            $fromNumber = $validated['from']
                ?? $this->resolveAssignedFromNumber($tenant->id, (string) $request->user()?->id, $provider->id)
                ?? (string) ($provider->credentials_encrypted['from_number'] ?? null);
        }

        $call = CallSession::query()->create([
            'tenant_id' => $tenant->id,
            'provider_account_id' => $provider->id,
            'initiated_by' => $request->user()?->id,
            'direction' => 'outbound',
            'status' => 'queued',
            'provider_call_id' => 'call_'.Str::lower(Str::random(24)),
            'from_number' => $fromNumber,
            'to_number' => $validated['to'],
            'metadata' => array_merge($requestMetadata, [
                'agent_id' => $validated['agent_id'] ?? null,
                'twiml_token' => Str::random(40),
                'controls' => ['muted' => false, 'on_hold' => false],
            ]),
        ]);

        $this->appendCallEvent(
            call: $call,
            eventType: 'call.initiated',
            providerEventType: 'outbound.queued',
            payload: ['source' => 'api', 'provider_call_id' => $call->provider_call_id]
        );

        $this->auditLogger->log(
            action: 'call.initiated',
            resourceType: 'call_session',
            resourceId: $call->id,
            tenantId: $tenant->id,
            actorId: $request->user()?->id,
            newValues: ['to' => $call->to_number, 'provider_account_id' => $provider->id, 'agent_id' => $validated['agent_id'] ?? null],
            request: $request
        );

        // Dispatch the actual outbound call asynchronously so this request returns immediately.
        $baseUrl = rtrim((string) config('app.url'), '/');
        $callMetadata = (array) ($call->metadata ?? []);
        $twimlToken = (string) ($callMetadata['twiml_token'] ?? '');
        $dialMode = (string) ($callMetadata['dial_mode'] ?? '');
        $dialQuery = $dialMode !== '' ? '&dial_mode='.urlencode($dialMode) : '';
        $scriptUrl = $provider->provider_type === 'vonage'
            ? $baseUrl.'/api/webhooks/vonage/ncco/outbound?call_session_id='.$call->id
            : $baseUrl.'/api/webhooks/twilio/twiml/outbound?call_session_id='.$call->id.'&token='.urlencode($twimlToken).$dialQuery;
        $statusCallbackUrl = $baseUrl.'/api/webhooks/'.$provider->provider_type;
        DispatchOutboundCallJob::dispatch(
            callSessionId: $call->id,
            providerAccountId: $provider->id,
            twimlUrl: $scriptUrl,
            statusCallbackUrl: $statusCallbackUrl,
        );

        $responseBody = [
            'data' => [
                'id' => $call->id,
                'status' => $call->status,
                'to_number' => $call->to_number,
                'from_number' => $call->from_number,
                'provider_call_id' => $call->provider_call_id,
                'provider' => [
                    'id' => $provider->id,
                    'label' => $provider->display_name,
                    'type' => $provider->provider_type,
                ],
                'created_at' => $call->created_at?->toISOString(),
            ],
        ];

        if (($idempotency['enabled'] ?? false) === true) {
            $this->idempotencyService->storeResponse($idempotency['record'], 202, $responseBody);
        }

        return response()->json($responseBody, 202);
    }

    public function bulkStore(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'lead_ids' => ['required_without:to_numbers', 'array', 'min:1', 'max:200'],
            'lead_ids.*' => ['required_with:lead_ids', 'uuid'],
            'to_numbers' => ['required_without:lead_ids', 'array', 'min:1', 'max:200'],
            'to_numbers.*' => ['required_with:to_numbers', 'regex:/^\\+[1-9]\\d{7,14}$/'],
            'agent_id' => ['nullable', 'uuid'],
            'from' => ['nullable', 'regex:/^\\+[1-9]\\d{7,14}$/'],
            'provider_account_id' => ['nullable', 'uuid'],
            'metadata' => ['nullable', 'array', 'max:10'],
        ]);

        $requestMetadata = (array) ($validated['metadata'] ?? []);
        if (! array_key_exists('dial_mode', $requestMetadata) || ! is_string($requestMetadata['dial_mode']) || $requestMetadata['dial_mode'] === '') {
            $requestMetadata['dial_mode'] = 'normal';
        }

        $provider = null;
        $fromNumber = null;
        if (! empty($validated['agent_id'])) {
            $agentIdentity = $this->resolveAgentOutboundIdentity($tenant->id, $validated['agent_id']);
            if (! $agentIdentity) {
                return response()->json([
                    'error' => [
                        'code' => 'AGENT_NUMBER_NOT_ASSIGNED',
                        'message' => 'Selected agent must have an active assigned validated number.',
                    ],
                ], 422);
            }

            $provider = $agentIdentity['provider'];
            $fromNumber = $agentIdentity['from_number'];
        } else {
            $providerQuery = ProviderAccount::query()
                ->where('tenant_id', $tenant->id)
                ->where('status', 'active');

            if (! empty($validated['provider_account_id'])) {
                $providerQuery->where('id', $validated['provider_account_id']);
            }

            $provider = $providerQuery->latest('created_at')->first();
            if (! $provider) {
                return response()->json([
                    'error' => [
                        'code' => 'PROVIDER_NOT_CONFIGURED',
                        'message' => 'No active provider account is available for this tenant.',
                    ],
                ], 422);
            }

            $fromNumber = $validated['from']
                ?? $this->resolveAssignedFromNumber($tenant->id, (string) $request->user()?->id, $provider->id)
                ?? (string) ($provider->credentials_encrypted['from_number'] ?? null);
        }

        $toNumbers = [];
        if (! empty($validated['to_numbers'])) {
            $toNumbers = array_values(array_unique(array_map('strval', (array) $validated['to_numbers'])));
        } else {
            $leadIds = array_values(array_unique(array_map('strval', (array) $validated['lead_ids'])));
            $phones = \App\Models\Lead::query()
                ->where('tenant_id', $tenant->id)
                ->whereIn('id', $leadIds)
                ->pluck('phone', 'id')
                ->toArray();
            foreach ($leadIds as $leadId) {
                $phone = (string) ($phones[$leadId] ?? '');
                if ($phone !== '') {
                    $toNumbers[] = $phone;
                }
            }
        }

        if ($toNumbers === []) {
            return response()->json([
                'error' => [
                    'code' => 'RECIPIENTS_EMPTY',
                    'message' => 'No valid recipients found for bulk calling.',
                ],
            ], 422);
        }

        $batchId = (string) Str::uuid();
        $calls = [];
        $baseUrl = rtrim((string) config('app.url'), '/');
        $statusCallbackUrl = $baseUrl.'/api/webhooks/'.$provider->provider_type;

        foreach ($toNumbers as $toNumber) {
            $call = CallSession::query()->create([
                'tenant_id' => $tenant->id,
                'provider_account_id' => $provider->id,
                'initiated_by' => $request->user()?->id,
                'direction' => 'outbound',
                'status' => 'queued',
                'provider_call_id' => 'call_'.Str::lower(Str::random(24)),
                'from_number' => $fromNumber,
                'to_number' => $toNumber,
                'metadata' => array_merge($requestMetadata, [
                    'agent_id' => $validated['agent_id'] ?? null,
                    'bulk_batch_id' => $batchId,
                    'twiml_token' => Str::random(40),
                    'controls' => ['muted' => false, 'on_hold' => false],
                ]),
            ]);

            $this->appendCallEvent(
                call: $call,
                eventType: 'call.initiated',
                providerEventType: 'outbound.bulk_queued',
                payload: ['source' => 'api', 'bulk_batch_id' => $batchId, 'provider_call_id' => $call->provider_call_id]
            );

            $callMetadata = (array) ($call->metadata ?? []);
            $twimlToken = (string) ($callMetadata['twiml_token'] ?? '');
            $dialMode = (string) ($callMetadata['dial_mode'] ?? '');
            $dialQuery = $dialMode !== '' ? '&dial_mode='.urlencode($dialMode) : '';
            $scriptUrl = $provider->provider_type === 'vonage'
                ? $baseUrl.'/api/webhooks/vonage/ncco/outbound?call_session_id='.$call->id
                : $baseUrl.'/api/webhooks/twilio/twiml/outbound?call_session_id='.$call->id.'&token='.urlencode($twimlToken).$dialQuery;

            DispatchOutboundCallJob::dispatch(
                callSessionId: $call->id,
                providerAccountId: $provider->id,
                twimlUrl: $scriptUrl,
                statusCallbackUrl: $statusCallbackUrl,
            );

            $calls[] = [
                'id' => $call->id,
                'status' => $call->status,
                'to_number' => $call->to_number,
                'from_number' => $call->from_number,
                'provider_call_id' => $call->provider_call_id,
                'created_at' => $call->created_at?->toISOString(),
            ];
        }

        $this->auditLogger->log(
            action: 'call.bulk_initiated',
            resourceType: 'call_session',
            resourceId: null,
            tenantId: $tenant->id,
            actorId: $request->user()?->id,
            newValues: [
                'bulk_batch_id' => $batchId,
                'count' => count($calls),
                'provider_account_id' => $provider->id,
                'agent_id' => $validated['agent_id'] ?? null,
            ],
            request: $request
        );

        return response()->json([
            'data' => [
                'bulk_batch_id' => $batchId,
                'count' => count($calls),
                'calls' => $calls,
            ],
        ], 202);
    }

    public function dispatchNow(Request $request, string $id, ProviderAdapterManager $adapterManager): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $call = CallSession::query()
            ->with('providerAccount')
            ->where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        if ($call->providerAccount?->status !== 'active') {
            return response()->json([
                'error' => [
                    'code' => 'PROVIDER_NOT_ACTIVE',
                    'message' => 'Provider is missing or inactive for this call.',
                ],
            ], 422);
        }

        if (! in_array($call->status, ['queued', 'failed', 'busy', 'no_answer', 'timeout', 'rejected', 'canceled'], true)) {
            return response()->json([
                'error' => [
                    'code' => 'CALL_NOT_DISPATCHABLE',
                    'message' => 'Call cannot be dispatched in its current state.',
                ],
            ], 409);
        }

        $provider = $call->providerAccount;
        $baseUrl = rtrim((string) config('app.url'), '/');
        $metadata = (array) ($call->metadata ?? []);
        if (! isset($metadata['twiml_token']) || ! is_string($metadata['twiml_token']) || $metadata['twiml_token'] === '') {
            $metadata['twiml_token'] = Str::random(40);
            $call->metadata = $metadata;
            $call->save();
        }
        if (! array_key_exists('dial_mode', $metadata) || ! is_string($metadata['dial_mode']) || $metadata['dial_mode'] === '') {
            $metadata['dial_mode'] = 'normal';
            $call->metadata = $metadata;
            $call->save();
        }
        $twimlToken = (string) ($metadata['twiml_token'] ?? '');
        $dialMode = (string) ($metadata['dial_mode'] ?? '');
        $dialQuery = $dialMode !== '' ? '&dial_mode='.urlencode($dialMode) : '';
        $scriptUrl = $provider->provider_type === 'vonage'
            ? $baseUrl.'/api/webhooks/vonage/ncco/outbound?call_session_id='.$call->id
            : $baseUrl.'/api/webhooks/twilio/twiml/outbound?call_session_id='.$call->id.'&token='.urlencode($twimlToken).$dialQuery;
        $statusCallbackUrl = $baseUrl.'/api/webhooks/'.$provider->provider_type;

        $result = $adapterManager
            ->for($provider->provider_type)
            ->makeOutboundCall(
                credentials: (array) $provider->credentials_encrypted,
                to: (string) $call->to_number,
                from: (string) ($call->from_number ?? ''),
                twimlUrl: $scriptUrl,
                statusCallbackUrl: $statusCallbackUrl,
            );

        if (($result['ok'] ?? false) === true && ! empty($result['provider_call_id'])) {
            $call->provider_call_id = (string) $result['provider_call_id'];
            $call->failure_reason = null;
            if ($call->status !== 'queued') {
                $call->status = 'queued';
            }
            $call->save();

            $this->appendCallEvent(
                call: $call,
                eventType: 'call.updated',
                providerEventType: 'outbound.dispatch_now',
                payload: [
                    'provider_call_id' => (string) $result['provider_call_id'],
                    'mode' => (string) ($result['mode'] ?? 'live'),
                ]
            );

            return response()->json(['data' => $this->serializeCall($call->fresh('providerAccount'))], 200);
        }

        $call->status = 'failed';
        $call->failure_reason = (string) ($result['message'] ?? 'Provider dispatch failed.');
        $call->save();

        $this->appendCallEvent(
            call: $call,
            eventType: 'call.failed',
            providerEventType: 'outbound.dispatch_error',
            payload: ['message' => $call->failure_reason, 'code' => (string) ($result['code'] ?? 'PROVIDER_CALL_FAILED')]
        );

        return response()->json([
            'error' => [
                'code' => (string) ($result['code'] ?? 'PROVIDER_CALL_FAILED'),
                'message' => (string) ($result['message'] ?? 'Provider dispatch failed.'),
            ],
        ], 422);
    }

    public function index(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $calls = CallSession::query()
            ->with('providerAccount')
            ->where('tenant_id', $tenant->id)
            ->when($request->filled('status'), fn ($q) => $q->where('status', (string) $request->input('status')))
            ->when($request->filled('provider_account_id'), fn ($q) => $q->where('provider_account_id', (string) $request->input('provider_account_id')))
            ->when($request->filled('to_number'), fn ($q) => $q->where('to_number', 'like', '%'.(string) $request->input('to_number').'%'))
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', (string) $request->input('from')))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', (string) $request->input('to')))
            ->latest('created_at')
            ->paginate((int) $request->integer('per_page', 25));

        return response()->json([
            'data' => collect($calls->items())->map(fn (CallSession $call) => $this->serializeCall($call))->values(),
            'meta' => [
                'pagination' => [
                    'total' => $calls->total(),
                    'per_page' => $calls->perPage(),
                    'current_page' => $calls->currentPage(),
                    'last_page' => $calls->lastPage(),
                ],
            ],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $call = CallSession::query()
            ->with(['providerAccount', 'initiatedByUser', 'events'])
            ->where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        return response()->json([
            'data' => [
                ...$this->serializeCall($call),
                'initiated_by' => $call->initiatedByUser ? [
                    'id' => $call->initiatedByUser->id,
                    'name' => trim($call->initiatedByUser->first_name.' '.$call->initiatedByUser->last_name),
                ] : null,
                'events' => $call->events
                    ->sortBy('occurred_at')
                    ->values()
                    ->map(fn (CallEvent $event) => [
                        'type' => $event->event_type,
                        'provider_event_type' => $event->provider_event_type,
                        'status_after' => $event->status_after,
                        'occurred_at' => $event->occurred_at?->toISOString(),
                        'payload' => $event->payload,
                    ]),
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $tenant = $request->attributes->get('tenant');
        $calls = CallSession::query()
            ->with('providerAccount')
            ->where('tenant_id', $tenant->id)
            ->when($request->filled('status'), fn ($q) => $q->where('status', (string) $request->input('status')))
            ->when($request->filled('provider_account_id'), fn ($q) => $q->where('provider_account_id', (string) $request->input('provider_account_id')))
            ->when($request->filled('to_number'), fn ($q) => $q->where('to_number', 'like', '%'.(string) $request->input('to_number').'%'))
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', (string) $request->input('from')))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', (string) $request->input('to')))
            ->latest('created_at')
            ->limit((int) $request->integer('limit', 1000))
            ->get();

        $filename = 'calls-export-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($calls) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'id',
                'status',
                'direction',
                'provider_label',
                'provider_type',
                'from_number',
                'to_number',
                'duration_seconds',
                'retry_count',
                'failure_reason',
                'started_at',
                'ended_at',
                'created_at',
            ]);

            foreach ($calls as $call) {
                fputcsv($handle, [
                    $call->id,
                    $call->status,
                    $call->direction,
                    $call->providerAccount?->display_name,
                    $call->providerAccount?->provider_type,
                    $call->from_number,
                    $call->to_number,
                    $call->duration_seconds,
                    $call->retry_count,
                    $call->failure_reason,
                    $call->started_at?->toISOString(),
                    $call->ended_at?->toISOString(),
                    $call->created_at?->toISOString(),
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function retry(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $idempotency = $this->idempotencyService->begin($request, $tenant?->id, $request->user()?->id);
        if (($idempotency['conflict'] ?? false) === true) {
            return response()->json([
                'error' => [
                    'code' => 'IDEMPOTENCY_CONFLICT',
                    'message' => 'Idempotency key has already been used with a different request payload.',
                ],
            ], 409);
        }
        if (($idempotency['in_progress'] ?? false) === true) {
            return response()->json([
                'error' => [
                    'code' => 'IDEMPOTENCY_IN_PROGRESS',
                    'message' => 'Request with this idempotency key is still processing.',
                ],
            ], 409);
        }
        if (($idempotency['replay'] ?? false) === true) {
            return response()
                ->json($idempotency['record']->response_body ?? [], (int) $idempotency['record']->response_status)
                ->header('X-Idempotent-Replay', 'true');
        }

        $call = CallSession::query()
            ->with('providerAccount')
            ->where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        if (! in_array($call->status, ['queued', 'failed', 'busy', 'no_answer', 'timeout', 'rejected', 'canceled'], true)) {
            return response()->json([
                'error' => [
                    'code' => 'CALL_NOT_RETRYABLE',
                    'message' => 'Only queued or failed calls can be retried.',
                ],
            ], 422);
        }

        $retry = CallSession::query()->create([
            'tenant_id' => $call->tenant_id,
            'provider_account_id' => $call->provider_account_id,
            'initiated_by' => $request->user()?->id,
            'direction' => $call->direction,
            'status' => 'queued',
            'provider_call_id' => 'call_'.Str::lower(Str::random(24)),
            'from_number' => $call->from_number,
            'to_number' => $call->to_number,
            'retry_count' => $call->retry_count + 1,
            'metadata' => array_merge((array) $call->metadata, [
                'retry_of_call_id' => $call->id,
                'twiml_token' => Str::random(40),
                'controls' => ['muted' => false, 'on_hold' => false],
            ]),
        ]);

        $this->appendCallEvent(
            call: $retry,
            eventType: 'call.initiated',
            providerEventType: 'outbound.retry',
            payload: ['source' => 'api', 'retry_of_call_id' => $call->id]
        );

        // Dispatch a fresh provider call attempt for manual retry/restart.
        $provider = ProviderAccount::query()->find($retry->provider_account_id);
        $providerType = $provider?->provider_type ?? 'twilio';
        $baseUrl = rtrim((string) config('app.url'), '/');
        $retryMetadata = (array) ($retry->metadata ?? []);
        $twimlToken = (string) ($retryMetadata['twiml_token'] ?? '');
        $dialMode = (string) ($retryMetadata['dial_mode'] ?? '');
        $dialQuery = $dialMode !== '' ? '&dial_mode='.urlencode($dialMode) : '';
        $scriptUrl = $providerType === 'vonage'
            ? $baseUrl.'/api/webhooks/vonage/ncco/outbound?call_session_id='.$retry->id
            : $baseUrl.'/api/webhooks/twilio/twiml/outbound?call_session_id='.$retry->id.'&token='.urlencode($twimlToken).$dialQuery;
        $statusCallbackUrl = $baseUrl.'/api/webhooks/'.$providerType;
        DispatchOutboundCallJob::dispatch(
            callSessionId: $retry->id,
            providerAccountId: $retry->provider_account_id,
            twimlUrl: $scriptUrl,
            statusCallbackUrl: $statusCallbackUrl,
        );

        $responseBody = [
            'data' => $this->serializeCall($retry->fresh('providerAccount')),
        ];
        if (($idempotency['enabled'] ?? false) === true) {
            $this->idempotencyService->storeResponse($idempotency['record'], 202, $responseBody);
        }

        return response()->json($responseBody, 202);
    }

    public function mute(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'muted' => ['nullable', 'boolean'],
        ]);

        $call = $this->resolveTenantCall($tenant->id, $id);
        if (in_array($call->status, self::TERMINAL_STATUSES, true)) {
            return response()->json([
                'error' => [
                    'code' => 'CALL_ALREADY_ENDED',
                    'message' => 'Cannot mute or unmute a completed call.',
                ],
            ], 409);
        }

        $muted = (bool) ($validated['muted'] ?? true);
        $controls = $this->setControlState($call, 'muted', $muted);
        $this->appendCallEvent(
            call: $call,
            eventType: $muted ? 'call.muted' : 'call.unmuted',
            providerEventType: 'agent.control',
            payload: ['muted' => $muted]
        );

        return response()->json([
            'data' => [
                ...$this->serializeCall($call->fresh('providerAccount')),
                'controls' => $controls,
            ],
        ]);
    }

    public function hold(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'on_hold' => ['nullable', 'boolean'],
        ]);

        $call = $this->resolveTenantCall($tenant->id, $id);
        if (in_array($call->status, self::TERMINAL_STATUSES, true)) {
            return response()->json([
                'error' => [
                    'code' => 'CALL_ALREADY_ENDED',
                    'message' => 'Cannot hold or resume a completed call.',
                ],
            ], 409);
        }

        $onHold = (bool) ($validated['on_hold'] ?? true);
        $controls = $this->setControlState($call, 'on_hold', $onHold);
        $this->appendCallEvent(
            call: $call,
            eventType: $onHold ? 'call.hold' : 'call.resume',
            providerEventType: 'agent.control',
            payload: ['on_hold' => $onHold]
        );

        return response()->json([
            'data' => [
                ...$this->serializeCall($call->fresh('providerAccount')),
                'controls' => $controls,
            ],
        ]);
    }

    public function end(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $call = $this->resolveTenantCall($tenant->id, $id);
        if (! in_array($call->status, self::TERMINAL_STATUSES, true)) {
            $call->loadMissing('providerAccount');
            $provider = $call->providerAccount;
            $providerHangup = null;
            if ($provider?->provider_type === 'twilio') {
                $credentials = (array) ($provider->credentials_encrypted ?? []);
                $accountSid = (string) ($credentials['account_sid'] ?? '');
                $authToken = (string) ($credentials['auth_token'] ?? '');
                $callSid = (string) ($call->provider_call_id ?? '');
                if (str_starts_with($callSid, 'CA') && $accountSid !== '' && $authToken !== '') {
                    try {
                        $response = Http::timeout(10)
                            ->withBasicAuth($accountSid, $authToken)
                            ->asForm()
                            ->post("https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Calls/{$callSid}.json", [
                                'Status' => 'completed',
                            ]);

                        $childrenResults = [];
                        if ($response->successful()) {
                            $children = Http::timeout(10)
                                ->withBasicAuth($accountSid, $authToken)
                                ->get("https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Calls.json", [
                                    'ParentCallSid' => $callSid,
                                    'PageSize' => 50,
                                ]);
                            if ($children->successful()) {
                                $calls = (array) ($children->json()['calls'] ?? []);
                                foreach ($calls as $child) {
                                    $childSid = (string) (($child['sid'] ?? '') ?: '');
                                    if ($childSid === '' || $childSid === $callSid) {
                                        continue;
                                    }
                                    try {
                                        $childResponse = Http::timeout(10)
                                            ->withBasicAuth($accountSid, $authToken)
                                            ->asForm()
                                            ->post("https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Calls/{$childSid}.json", [
                                                'Status' => 'completed',
                                            ]);
                                        $childrenResults[] = [
                                            'call_sid' => $childSid,
                                            'ok' => $childResponse->successful(),
                                            'status' => $childResponse->status(),
                                            'body' => $childResponse->successful() ? null : $childResponse->json(),
                                        ];
                                    } catch (\Throwable $exception) {
                                        $childrenResults[] = [
                                            'call_sid' => $childSid,
                                            'ok' => false,
                                            'error' => $exception->getMessage(),
                                        ];
                                    }
                                }
                            } else {
                                $childrenResults[] = [
                                    'ok' => false,
                                    'error' => 'Unable to list child calls.',
                                    'status' => $children->status(),
                                    'body' => $children->json(),
                                ];
                            }
                        }

                        $providerHangup = [
                            'ok' => $response->successful(),
                            'provider' => 'twilio',
                            'call_sid' => $callSid,
                            'status' => $response->status(),
                            'body' => $response->successful() ? null : $response->json(),
                            'children' => $childrenResults,
                        ];
                    } catch (\Throwable $exception) {
                        $providerHangup = [
                            'ok' => false,
                            'provider' => 'twilio',
                            'call_sid' => $callSid,
                            'error' => $exception->getMessage(),
                        ];
                    }
                } else {
                    $providerHangup = [
                        'ok' => false,
                        'provider' => 'twilio',
                        'call_sid' => $callSid,
                        'error' => 'Missing provider CallSid or credentials.',
                    ];
                }
            }

            if ($provider?->provider_type === 'twilio' && is_array($providerHangup) && ($providerHangup['ok'] ?? false) !== true) {
                $this->appendCallEvent(
                    call: $call,
                    eventType: 'call.end_failed',
                    providerEventType: 'agent.control',
                    payload: [
                        'ended_by' => (string) $request->user()?->id,
                        'provider_hangup' => $providerHangup,
                    ]
                );

                return response()->json([
                    'error' => [
                        'code' => 'PROVIDER_HANGUP_FAILED',
                        'message' => 'Unable to end the provider call. Check provider_hangup for details.',
                    ],
                    'data' => [
                        ...$this->serializeCall($call->fresh('providerAccount')),
                        'controls' => (array) (($call->metadata ?? [])['controls'] ?? ['muted' => false, 'on_hold' => false]),
                    ],
                    'provider_hangup' => $providerHangup,
                ], 502);
            }

            $call->status = 'canceled';
            $call->ended_at = now();
            $controls = $this->setControlState($call, 'on_hold', false, false);
            $call->save();
            $this->appendCallEvent(
                call: $call,
                eventType: 'call.ended',
                providerEventType: 'agent.control',
                payload: [
                    'ended_by' => (string) $request->user()?->id,
                    'provider_hangup' => $providerHangup,
                ]
            );

            return response()->json([
                'data' => [
                    ...$this->serializeCall($call->fresh('providerAccount')),
                    'controls' => $controls,
                ],
            ]);
        }

        return response()->json([
            'data' => [
                ...$this->serializeCall($call->fresh('providerAccount')),
                'controls' => (array) (($call->metadata ?? [])['controls'] ?? ['muted' => false, 'on_hold' => false]),
            ],
        ]);
    }

    public function transfer(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'to_agent_id' => ['required', 'uuid'],
            'mode' => ['nullable', 'in:warm,blind'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $call = $this->resolveTenantCall($tenant->id, $id);
        if (in_array($call->status, self::TERMINAL_STATUSES, true)) {
            return response()->json([
                'error' => [
                    'code' => 'CALL_ALREADY_ENDED',
                    'message' => 'Cannot transfer a completed call.',
                ],
            ], 409);
        }

        $targetIdentity = $this->resolveAgentOutboundIdentity($tenant->id, (string) $validated['to_agent_id']);
        if (! $targetIdentity) {
            return response()->json([
                'error' => [
                    'code' => 'AGENT_NUMBER_NOT_ASSIGNED',
                    'message' => 'Target agent must have an active assigned validated number.',
                ],
            ], 422);
        }

        $metadata = (array) $call->metadata;
        $metadata['transfer'] = [
            'to_agent_id' => $validated['to_agent_id'],
            'mode' => $validated['mode'] ?? 'warm',
            'note' => $validated['note'] ?? null,
            'to_number' => (string) $targetIdentity['from_number'],
            'transferred_by' => $request->user()?->id,
            'transferred_at' => now()->toISOString(),
        ];
        $call->metadata = $metadata;
        $call->save();

        $this->appendCallEvent(
            call: $call,
            eventType: 'call.transfer',
            providerEventType: 'agent.transfer',
            payload: $metadata['transfer']
        );

        if ($call->providerAccount?->provider_type === 'twilio') {
            $credentials = (array) ($call->providerAccount->credentials_encrypted ?? []);
            $accountSid = (string) ($credentials['account_sid'] ?? '');
            $authToken = (string) ($credentials['auth_token'] ?? '');
            $callSid = (string) ($call->provider_call_id ?? '');
            if (str_starts_with($callSid, 'CA') && $accountSid !== '' && $authToken !== '') {
                $twimlUrl = rtrim((string) config('app.url'), '/')
                    .'/api/webhooks/twilio/voice/transfer?call_session_id='.$call->id
                    .'&to='.urlencode((string) $metadata['transfer']['to_number'])
                    .'&mode='.urlencode((string) $metadata['transfer']['mode']);

                Http::timeout(10)
                    ->withBasicAuth($accountSid, $authToken)
                    ->asForm()
                    ->post("https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Calls/{$callSid}.json", [
                        'Url' => $twimlUrl,
                        'Method' => 'POST',
                    ]);
            }
        }

        return response()->json([
            'data' => [
                ...$this->serializeCall($call->fresh('providerAccount')),
                'transfer' => $metadata['transfer'],
            ],
        ]);
    }

    public function aiArtifact(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $call = CallSession::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        $artifact = CallAiArtifact::query()
            ->where('tenant_id', $tenant->id)
            ->where('call_session_id', $call->id)
            ->first();

        return response()->json([
            'data' => $artifact,
        ]);
    }

    public function aiProcess(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $call = CallSession::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        $artifact = CallAiArtifact::query()->updateOrCreate([
            'tenant_id' => $tenant->id,
            'call_session_id' => $call->id,
        ], [
            'status' => 'queued',
            'provider_mode' => 'mock',
            'metadata' => [
                'requested_by' => $request->user()?->id,
                'requested_at' => now()->toISOString(),
            ],
            'processed_at' => null,
        ]);

        ProcessCallAiArtifactJob::dispatch($artifact->id);

        return response()->json([
            'data' => $artifact,
        ], 202);
    }

    public function supervision(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'mode' => ['required', 'in:listen,whisper,barge'],
            'target_agent_id' => ['nullable', 'uuid'],
        ]);

        $call = $this->resolveTenantCall($tenant->id, $id);
        if (in_array($call->status, self::TERMINAL_STATUSES, true)) {
            return response()->json([
                'error' => [
                    'code' => 'CALL_ALREADY_ENDED',
                    'message' => 'Cannot supervise a completed call.',
                ],
            ], 409);
        }

        $metadata = (array) $call->metadata;
        $metadata['supervision'] = [
            'mode' => $validated['mode'],
            'target_agent_id' => $validated['target_agent_id'] ?? null,
            'supervisor_id' => $request->user()?->id,
            'changed_at' => now()->toISOString(),
        ];
        $call->metadata = $metadata;
        $call->save();

        $this->appendCallEvent(
            call: $call,
            eventType: 'call.supervision.'.$validated['mode'],
            providerEventType: 'supervisor.mode',
            payload: $metadata['supervision']
        );

        return response()->json([
            'data' => [
                ...$this->serializeCall($call->fresh('providerAccount')),
                'supervision' => $metadata['supervision'],
            ],
        ]);
    }

    public function recording(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $call = $this->resolveTenantCall($tenant->id, $id);
        if (! $call->recording_url) {
            return response()->json([
                'error' => [
                    'code' => 'CALL_RECORDING_NOT_FOUND',
                    'message' => 'Recording is not available for this call.',
                ],
            ], 404);
        }

        return response()->json([
            'data' => [
                'call_id' => $call->id,
                'recording_url' => $this->resolvePlaybackUrl((string) $call->recording_url),
                'recording_duration' => $call->recording_duration,
            ],
        ]);
    }

    public function tag(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'tags' => ['required', 'array', 'min:1'],
            'tags.*' => ['string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $call = $this->resolveTenantCall($tenant->id, $id);
        $call->recording_tags = array_values(array_unique((array) $validated['tags']));
        if (array_key_exists('notes', $validated)) {
            $call->recording_notes = $validated['notes'];
        }
        $call->save();

        $this->appendCallEvent(
            call: $call,
            eventType: 'call.recording.tagged',
            providerEventType: 'agent.control',
            payload: [
                'tags' => $call->recording_tags,
                'notes' => $call->recording_notes,
                'actor_id' => $request->user()?->id,
            ]
        );

        return response()->json([
            'data' => [
                'call_id' => $call->id,
                'recording_tags' => $call->recording_tags,
                'recording_notes' => $call->recording_notes,
            ],
        ]);
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
            'recording_url' => $call->recording_url,
            'recording_duration' => $call->recording_duration,
            'recording_tags' => $call->recording_tags,
            'recording_notes' => $call->recording_notes,
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
            'metadata' => (array) ($call->metadata ?? []),
            'controls' => array_merge(
                ['muted' => false, 'on_hold' => false],
                (array) (($call->metadata ?? [])['controls'] ?? [])
            ),
        ];
    }

    private function resolvePlaybackUrl(string $recordingUrl): string
    {
        if (Str::startsWith($recordingUrl, ['http://', 'https://'])) {
            return $recordingUrl;
        }

        $disk = config('filesystems.default', 'local');
        if (config('filesystems.disks.s3.bucket')) {
            $disk = 's3';
        }

        try {
            return Storage::disk($disk)->temporaryUrl($recordingUrl, now()->addMinutes(15));
        } catch (\Throwable) {
            return Storage::disk($disk)->url($recordingUrl);
        }
    }

    private function resolveTenantCall(string $tenantId, string $id): CallSession
    {
        return CallSession::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->firstOrFail();
    }

    private function setControlState(
        CallSession $call,
        string $key,
        bool $value,
        bool $saveImmediately = true
    ): array {
        $metadata = (array) $call->metadata;
        $controls = array_merge(['muted' => false, 'on_hold' => false], (array) ($metadata['controls'] ?? []));
        $controls[$key] = $value;
        $metadata['controls'] = $controls;
        $call->metadata = $metadata;
        if ($saveImmediately) {
            $call->save();
        }

        return $controls;
    }

    private function appendCallEvent(
        CallSession $call,
        string $eventType,
        string $providerEventType,
        array $payload = []
    ): void {
        CallEvent::query()->create([
            'tenant_id' => $call->tenant_id,
            'call_session_id' => $call->id,
            'provider_account_id' => $call->provider_account_id,
            'event_type' => $eventType,
            'provider_event_type' => $providerEventType,
            'status_after' => $call->status,
            'payload' => $payload,
            'occurred_at' => now(),
        ]);
    }

    private function resolveAssignedFromNumber(string $tenantId, string $userId, string $providerId): ?string
    {
        if ($userId === '') {
            return null;
        }

        $assignmentQuery = AgentPhoneAssignment::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active');

        $numberId = null;

        // Backward compatibility: older datasets used user_id while newer schema uses agent_id.
        if (Schema::hasColumn('agent_phone_assignments', 'user_id')) {
            $numberId = (clone $assignmentQuery)
                ->where('user_id', $userId)
                ->value('provider_phone_number_id');
        }

        if (! $numberId && Schema::hasColumn('agent_phone_assignments', 'agent_id')) {
            $agentId = $this->resolveAgentIdForUser($tenantId, $userId);
            if ($agentId) {
                $numberId = (clone $assignmentQuery)
                    ->where('agent_id', $agentId)
                    ->value('provider_phone_number_id');
            }
        }

        if (! $numberId) {
            return null;
        }

        $number = ProviderPhoneNumber::query()
            ->where('tenant_id', $tenantId)
            ->where('provider_account_id', $providerId)
            ->where('id', $numberId)
            ->where('status', 'active')
            ->where('is_validated', true)
            ->first();

        return $number?->phone_number;
    }

    private function resolveAgentOutboundIdentity(string $tenantId, string $agentId): ?array
    {
        $agent = Agent::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $agentId)
            ->where('status', 'active')
            ->first();
        if (! $agent) {
            return null;
        }

        $assignment = AgentPhoneAssignment::query()
            ->where('tenant_id', $tenantId)
            ->where('agent_id', $agentId)
            ->where('status', 'active')
            ->latest('updated_at')
            ->first();
        if (! $assignment) {
            return null;
        }

        $number = ProviderPhoneNumber::query()
            ->with('providerAccount')
            ->where('tenant_id', $tenantId)
            ->where('id', $assignment->provider_phone_number_id)
            ->where('status', 'active')
            ->where('is_validated', true)
            ->first();
        if (! $number || ! $number->providerAccount || $number->providerAccount->status !== 'active') {
            return null;
        }

        return [
            'provider' => $number->providerAccount,
            'from_number' => $number->phone_number,
        ];
    }

    private function resolveAgentIdForUser(string $tenantId, string $userId): ?string
    {
        return Agent::query()
            ->where('tenant_id', $tenantId)
            ->where('created_by', $userId)
            ->where('status', 'active')
            ->latest('created_at')
            ->value('id');
    }
}
