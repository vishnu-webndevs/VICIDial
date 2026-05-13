<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\RunCampaignTickJob;
use App\Models\AgentSession;
use App\Models\Campaign;
use App\Models\CampaignAgentAssignment;
use App\Models\CampaignRun;
use App\Models\DialQueueItem;
use App\Models\Lead;
use App\Models\LeadList;
use App\Models\LeadTimelineItem;
use App\Models\Message;
use App\Models\MessageThread;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CampaignController extends Controller
{
    private const CAMPAIGN_TYPES = ['outbound_call', 'sms', 'whatsapp', 'outreach'];
    private const MESSAGE_CHANNELS = ['sms', 'whatsapp'];

    public function index(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $type = $request->filled('type') ? (string) $request->query('type') : null;
        $campaigns = Campaign::query()
            ->where('tenant_id', $tenant->id)
            ->when($type, fn ($q) => $q->where('type', $type))
            ->latest('updated_at')
            ->get()
            ->map(fn (Campaign $campaign) => $this->serializeCampaign($campaign))
            ->values();

        return response()->json(['data' => $campaigns]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:outbound_call,sms,whatsapp,outreach,auto,manual'],
            'status' => ['nullable', 'in:draft,running,paused,completed'],
            'lead_list_name' => ['nullable', 'string', 'max:255'],
            'lead_list_ids' => ['nullable', 'array'],
            'lead_list_ids.*' => ['uuid'],
            'schedule_window' => ['nullable', 'string', 'max:255'],
            'retry_limit' => ['nullable', 'integer', 'min:0', 'max:10'],
            'queue_size' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'calls_per_minute' => ['nullable', 'integer', 'min:1', 'max:500'],
            'auto_pause_when_no_agents' => ['nullable', 'boolean'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:100'],
            'preferred_provider_account_id' => ['nullable', 'uuid'],
            'message_content' => ['nullable', 'string', 'max:5000'],
            'message_template_key' => ['nullable', 'string', 'max:80'],
            'message_variables' => ['nullable', 'array', 'max:50'],
            'message_channel' => ['nullable', 'in:sms,whatsapp'],
        ]);

        $type = in_array((string) $validated['type'], ['auto', 'manual'], true) ? 'outbound_call' : (string) $validated['type'];

        $requestedLeadListIds = collect($validated['lead_list_ids'] ?? [])->filter()->unique()->values();
        $leadLists = $requestedLeadListIds->isEmpty()
            ? collect()
            : LeadList::query()
                ->where('tenant_id', $tenant->id)
                ->whereIn('id', $requestedLeadListIds->all())
                ->get(['id', 'name']);
        if ($requestedLeadListIds->isNotEmpty() && $leadLists->count() !== $requestedLeadListIds->count()) {
            return response()->json([
                'error' => [
                    'code' => 'INVALID_LEAD_LIST',
                    'message' => 'One or more selected lead lists are invalid for this tenant.',
                ],
            ], 422);
        }
        $settings = [
            'lead_list_ids' => $leadLists->pluck('id')->values()->all(),
        ];
        $messageCampaignTypes = ['sms', 'whatsapp', 'outreach'];
        if (in_array($type, $messageCampaignTypes, true)) {
            $channel = $type === 'sms'
                ? 'sms'
                : ($type === 'whatsapp'
                    ? 'whatsapp'
                    : (string) ($validated['message_channel'] ?? 'sms'));

            if (! in_array($channel, self::MESSAGE_CHANNELS, true)) {
                return response()->json([
                    'error' => [
                        'code' => 'MESSAGE_CHANNEL_INVALID',
                        'message' => 'Invalid message_channel for this campaign.',
                    ],
                ], 422);
            }

            $content = trim((string) ($validated['message_content'] ?? ''));
            $templateKey = trim((string) ($validated['message_template_key'] ?? ''));
            if ($content === '' && $templateKey === '') {
                return response()->json([
                    'error' => [
                        'code' => 'MESSAGE_CONTENT_REQUIRED',
                        'message' => 'Message campaigns require message_content or message_template_key.',
                    ],
                ], 422);
            }

            if (empty($validated['preferred_provider_account_id'])) {
                return response()->json([
                    'error' => [
                        'code' => 'PROVIDER_REQUIRED',
                        'message' => 'Select a provider/connection for this campaign.',
                    ],
                ], 422);
            }

            $settings = array_merge($settings, [
                'channel' => $channel,
                'provider_account_id' => $validated['preferred_provider_account_id'] ?? null,
                'message_content' => $content !== '' ? $content : null,
                'message_template_key' => $templateKey !== '' ? $templateKey : null,
                'message_variables' => (array) ($validated['message_variables'] ?? []),
            ]);
        }

        $campaign = Campaign::query()->create([
            'tenant_id' => $tenant->id,
            'created_by' => $request->user()?->id,
            'name' => $validated['name'],
            'type' => $type,
            'status' => $validated['status'] ?? 'draft',
            'lead_list_name' => $validated['lead_list_name']
                ?? ($leadLists->isNotEmpty() ? $leadLists->pluck('name')->implode(', ') : null),
            'schedule_window' => $validated['schedule_window'] ?? null,
            'retry_limit' => (int) ($validated['retry_limit'] ?? 2),
            'queue_size' => (int) ($validated['queue_size'] ?? 50),
            'calls_per_minute' => (int) ($validated['calls_per_minute'] ?? 20),
            'auto_pause_when_no_agents' => (bool) ($validated['auto_pause_when_no_agents'] ?? true),
            'priority' => (int) ($validated['priority'] ?? 0),
            'preferred_provider_account_id' => $validated['preferred_provider_account_id'] ?? null,
            'settings' => $settings,
        ]);

        return response()->json(['data' => $this->serializeCampaign($campaign)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $campaign = Campaign::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'type' => ['sometimes', 'required', 'in:outbound_call,sms,whatsapp,outreach,auto,manual'],
            'status' => ['sometimes', 'required', 'in:draft,running,paused,completed'],
            'lead_list_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'lead_list_ids' => ['sometimes', 'array'],
            'lead_list_ids.*' => ['uuid'],
            'schedule_window' => ['sometimes', 'nullable', 'string', 'max:255'],
            'retry_limit' => ['sometimes', 'required', 'integer', 'min:0', 'max:10'],
            'queue_size' => ['sometimes', 'required', 'integer', 'min:1', 'max:1000'],
            'calls_per_minute' => ['sometimes', 'required', 'integer', 'min:1', 'max:500'],
            'auto_pause_when_no_agents' => ['sometimes', 'required', 'boolean'],
            'priority' => ['sometimes', 'required', 'integer', 'min:0', 'max:100'],
            'preferred_provider_account_id' => ['sometimes', 'nullable', 'uuid'],
            'message_content' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'message_template_key' => ['sometimes', 'nullable', 'string', 'max:80'],
            'message_variables' => ['sometimes', 'nullable', 'array', 'max:50'],
            'message_channel' => ['sometimes', 'nullable', 'in:sms,whatsapp'],
        ]);

        if (array_key_exists('type', $validated) && in_array((string) $validated['type'], ['auto', 'manual'], true)) {
            $validated['type'] = 'outbound_call';
        }

        if (array_key_exists('lead_list_ids', $validated)) {
            $requestedLeadListIds = collect($validated['lead_list_ids'] ?? [])->filter()->unique()->values();
            $leadLists = $requestedLeadListIds->isEmpty()
                ? collect()
                : LeadList::query()
                    ->where('tenant_id', $tenant->id)
                    ->whereIn('id', $requestedLeadListIds->all())
                    ->get(['id', 'name']);
            if ($requestedLeadListIds->isNotEmpty() && $leadLists->count() !== $requestedLeadListIds->count()) {
                return response()->json([
                    'error' => [
                        'code' => 'INVALID_LEAD_LIST',
                        'message' => 'One or more selected lead lists are invalid for this tenant.',
                    ],
                ], 422);
            }

            $settings = (array) ($campaign->settings ?? []);
            $settings['lead_list_ids'] = $leadLists->pluck('id')->values()->all();
            $validated['settings'] = $settings;
            if (! array_key_exists('lead_list_name', $validated)) {
                $validated['lead_list_name'] = $leadLists->isNotEmpty()
                    ? $leadLists->pluck('name')->implode(', ')
                    : null;
            }
        }

        $resolvedType = (string) ($validated['type'] ?? $campaign->type);
        $isMessageCampaign = in_array($resolvedType, ['sms', 'whatsapp', 'outreach'], true);
        $isMessageUpdate = $isMessageCampaign
            && (array_key_exists('message_content', $validated)
                || array_key_exists('message_template_key', $validated)
                || array_key_exists('message_variables', $validated)
                || array_key_exists('preferred_provider_account_id', $validated)
                || array_key_exists('message_channel', $validated)
                || array_key_exists('type', $validated));

        if ($isMessageUpdate) {
            $settings = (array) (($validated['settings'] ?? null) ?: ($campaign->settings ?? []));
            $channel = $resolvedType === 'sms'
                ? 'sms'
                : ($resolvedType === 'whatsapp'
                    ? 'whatsapp'
                    : (string) ($validated['message_channel'] ?? ($settings['channel'] ?? 'sms')));

            $settings['channel'] = $channel;
            if (array_key_exists('preferred_provider_account_id', $validated)) {
                $settings['provider_account_id'] = $validated['preferred_provider_account_id'];
            }
            if (array_key_exists('message_content', $validated)) {
                $content = trim((string) ($validated['message_content'] ?? ''));
                $settings['message_content'] = $content !== '' ? $content : null;
            }
            if (array_key_exists('message_template_key', $validated)) {
                $templateKey = trim((string) ($validated['message_template_key'] ?? ''));
                $settings['message_template_key'] = $templateKey !== '' ? $templateKey : null;
            }
            if (array_key_exists('message_variables', $validated)) {
                $settings['message_variables'] = (array) ($validated['message_variables'] ?? []);
            }
            $validated['settings'] = $settings;
        }

        unset($validated['message_content'], $validated['message_template_key'], $validated['message_variables']);
        $campaign->fill($validated);
        $campaign->save();

        return response()->json(['data' => $this->serializeCampaign($campaign)]);
    }

    public function start(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $campaign = Campaign::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        $settings = (array) ($campaign->settings ?? []);
        $channel = (string) ($settings['channel'] ?? '');
        $isMessageCampaign = in_array($campaign->type, ['sms', 'whatsapp', 'outreach'], true);
        if ($isMessageCampaign) {
            if (! in_array($channel, self::MESSAGE_CHANNELS, true)) {
                return response()->json([
                    'error' => [
                        'code' => 'MESSAGE_CHANNEL_INVALID',
                        'message' => 'Message channel is missing or invalid for this campaign.',
                    ],
                ], 422);
            }
            $providerValidation = $this->validateMessagingProvider($tenant->id, $campaign, $channel);
            if (($providerValidation['ok'] ?? false) !== true) {
                return response()->json([
                    'error' => [
                        'code' => (string) ($providerValidation['code'] ?? 'PROVIDER_INVALID'),
                        'message' => (string) ($providerValidation['message'] ?? 'Provider configuration is invalid.'),
                    ],
                ], 422);
            }
        }

        $run = CampaignRun::query()
            ->where('tenant_id', $tenant->id)
            ->where('campaign_id', $campaign->id)
            ->whereIn('status', ['queued', 'running', 'paused'])
            ->latest('created_at')
            ->first();

        if (! $run || $run->status === 'completed' || $run->status === 'stopped') {
            $run = CampaignRun::query()->create([
                'tenant_id' => $tenant->id,
                'campaign_id' => $campaign->id,
                'started_by' => $request->user()?->id,
                'status' => 'running',
                'calls_per_minute' => $campaign->calls_per_minute,
                'started_at' => now(),
                'pacing_window_started_at' => now(),
                'calls_dispatched_in_window' => 0,
            ]);
            if ($isMessageCampaign) {
                $this->dispatchMessageCampaign($campaign, $run, $channel);
            } else {
                $this->seedQueue($campaign, $run);
            }
        } else {
            $run->status = 'running';
            $run->paused_at = null;
            $run->started_by = $run->started_by ?: $request->user()?->id;
            $run->started_at = $run->started_at ?: now();
            $run->save();
        }

        $campaign->status = 'running';
        $campaign->save();

        $assignedAgentIds = CampaignAgentAssignment::query()
            ->where('tenant_id', $tenant->id)
            ->where('campaign_id', $campaign->id)
            ->pluck('agent_id')
            ->map(fn ($id) => (string) $id)
            ->filter()
            ->unique()
            ->values();

        foreach ($assignedAgentIds as $agentId) {
            AgentSession::query()->updateOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'agent_id' => $agentId,
                ],
                [
                    'status' => 'available',
                    'capacity' => 1,
                    'available_since' => now(),
                    'last_heartbeat_at' => now(),
                ]
            );
        }

        if ($isMessageCampaign && $run) {
            $this->recoverMessageCampaignIfStuck($campaign, $run, $channel);
        }

        if (! $isMessageCampaign) {
            RunCampaignTickJob::dispatch($run->id);
        }

        return response()->json([
            'data' => [
                'campaign' => $this->serializeCampaign($campaign),
                'run' => $this->serializeRun($run->fresh()),
            ],
        ], 202);
    }

    public function pause(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $campaign = Campaign::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        $run = CampaignRun::query()
            ->where('tenant_id', $tenant->id)
            ->where('campaign_id', $campaign->id)
            ->where('status', 'running')
            ->latest('created_at')
            ->first();

        if ($run) {
            $run->status = 'paused';
            $run->paused_at = now();
            $run->paused_by = $request->user()?->id;
            $run->save();
        }

        $campaign->status = 'paused';
        $campaign->save();

        return response()->json([
            'data' => [
                'campaign' => $this->serializeCampaign($campaign),
                'run' => $run ? $this->serializeRun($run) : null,
            ],
        ]);
    }

    public function stop(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $campaign = Campaign::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        $run = CampaignRun::query()
            ->where('tenant_id', $tenant->id)
            ->where('campaign_id', $campaign->id)
            ->whereIn('status', ['queued', 'running', 'paused'])
            ->latest('created_at')
            ->first();

        if ($run) {
            $run->status = 'stopped';
            $run->stopped_at = now();
            $run->save();
        }

        $campaign->status = 'completed';
        $campaign->save();

        return response()->json([
            'data' => [
                'campaign' => $this->serializeCampaign($campaign),
                'run' => $run ? $this->serializeRun($run) : null,
            ],
        ]);
    }

    public function status(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $campaign = Campaign::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();
        $run = CampaignRun::query()
            ->where('tenant_id', $tenant->id)
            ->where('campaign_id', $campaign->id)
            ->latest('created_at')
            ->first();

        $isMessageCampaign = in_array($campaign->type, ['sms', 'whatsapp', 'outreach'], true);
        if ($isMessageCampaign) {
            $settings = (array) ($campaign->settings ?? []);
            $channel = (string) ($settings['channel'] ?? '');
            if (! in_array($channel, self::MESSAGE_CHANNELS, true)) {
                $channel = $campaign->type === 'sms' ? 'sms' : 'whatsapp';
            }

            if ($run) {
                $this->recoverMessageCampaignIfStuck($campaign, $run, $channel);
                $run->refresh();
            }

            if ($campaign->type === 'whatsapp') {
                $this->syncTwilioWhatsAppStatuses(
                    tenantId: (string) $tenant->id,
                    campaignId: (string) $campaign->id,
                    campaignRunId: $run?->id ? (string) $run->id : null,
                );
            }

            $queueCounts = (object) [
                'pending' => (int) ($run?->queued_items ?? 0),
                'in_progress' => 0,
                'completed' => (int) ($run?->completed_items ?? 0),
                'failed' => (int) ($run?->failed_items ?? 0),
            ];
        } else {
            $queueCounts = DialQueueItem::query()
                ->where('tenant_id', $tenant->id)
                ->where('campaign_id', $campaign->id)
                ->selectRaw("SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending")
                ->selectRaw("SUM(CASE WHEN status IN ('processing','dialed') THEN 1 ELSE 0 END) as in_progress")
                ->selectRaw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed")
                ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed")
                ->first();
        }

        $agents = AgentSession::query()
            ->with('agent:id,company_number,status')
            ->whereNotNull('agent_id')
            ->where('tenant_id', $tenant->id)
            ->orderBy('status')
            ->orderByDesc('last_heartbeat_at')
            ->limit(50)
            ->get()
            ->map(fn (AgentSession $session) => [
                'id' => $session->id,
                'agent_id' => $session->agent_id,
                'name' => (string) ($session->agent?->company_number ?? ''),
                'status' => $session->status,
                'capacity' => $session->capacity,
                'active_assignments' => $session->active_assignments,
                'last_heartbeat_at' => $session->last_heartbeat_at?->toISOString(),
            ]);

        return response()->json([
            'data' => [
                'campaign' => $this->serializeCampaign($campaign),
                'run' => $run ? $this->serializeRun($run) : null,
                'queue' => [
                    'pending' => (int) ($queueCounts->pending ?? 0),
                    'in_progress' => (int) ($queueCounts->in_progress ?? 0),
                    'completed' => (int) ($queueCounts->completed ?? 0),
                    'failed' => (int) ($queueCounts->failed ?? 0),
                ],
                'agents' => $agents,
            ],
        ]);
    }

    private function syncTwilioWhatsAppStatuses(string $tenantId, string $campaignId, ?string $campaignRunId): void
    {
        $query = Message::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['queued', 'accepted', 'sending', 'sent'])
            ->where('provider_message_id', 'like', 'SM%')
            ->where('provider_message_id', 'not like', 'wa_mock_%');

        if ($campaignRunId) {
            $query->where('metadata->campaign_run_id', $campaignRunId);
        } else {
            $query->where('metadata->campaign_id', $campaignId);
        }

        $messages = $query
            ->latest('sent_at')
            ->limit(15)
            ->get();

        if ($messages->isEmpty()) {
            return;
        }

        foreach ($messages as $message) {
            $providerAccountId = (string) (($message->metadata['provider_account_id'] ?? null) ?: '');
            if ($providerAccountId === '') {
                continue;
            }

            $provider = \App\Models\ProviderAccount::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $providerAccountId)
                ->where('status', 'active')
                ->first();
            if (! $provider || $provider->provider_type !== 'twilio') {
                continue;
            }

            $credentials = (array) ($provider->credentials_encrypted ?? []);
            $sid = (string) ($credentials['account_sid'] ?? '');
            $token = (string) ($credentials['auth_token'] ?? '');
            if ($sid === '' || $token === '') {
                continue;
            }

            try {
                $response = Http::timeout(8)
                    ->withBasicAuth($sid, $token)
                    ->acceptJson()
                    ->get("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages/{$message->provider_message_id}.json");

                if (! $response->successful()) {
                    continue;
                }

                $status = strtolower((string) ($response->json('status') ?? ''));
                if ($status === '') {
                    continue;
                }

                if ($message->status !== $status) {
                    $message->status = $status;
                    if (in_array($status, ['delivered', 'read'], true)) {
                        $message->delivered_at = $message->delivered_at ?: now();
                    }
                    $message->metadata = array_merge((array) ($message->metadata ?? []), [
                        'status_polled_at' => now()->toISOString(),
                        'status_polled' => [
                            'status' => $status,
                            'provider_account_id' => $providerAccountId,
                        ],
                    ]);
                    $message->save();
                }
            } catch (\Throwable $e) {
                Log::warning('Twilio message status poll failed.', [
                    'tenant_id' => $tenantId,
                    'provider_account_id' => $providerAccountId,
                    'message_id' => $message->id,
                    'provider_message_id' => $message->provider_message_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function stats(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $campaign = Campaign::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        $isMessageCampaign = in_array($campaign->type, ['sms', 'whatsapp', 'outreach'], true);
        if ($isMessageCampaign) {
            $run = CampaignRun::query()
                ->where('tenant_id', $tenant->id)
                ->where('campaign_id', $campaign->id)
                ->latest('created_at')
                ->first();
            $totals = (object) [
                'pending' => (int) ($run?->queued_items ?? 0),
                'in_progress' => 0,
                'completed' => (int) ($run?->completed_items ?? 0),
                'failed' => (int) ($run?->failed_items ?? 0),
            ];
        } else {
            $run = CampaignRun::query()
                ->where('tenant_id', $tenant->id)
                ->where('campaign_id', $campaign->id)
                ->latest('created_at')
                ->first();

            $totals = DialQueueItem::query()
                ->where('tenant_id', $tenant->id)
                ->where('campaign_id', $campaign->id)
                ->when($run, fn($q) => $q->where('campaign_run_id', $run->id))
                ->selectRaw("SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending")
                ->selectRaw("SUM(CASE WHEN status IN ('processing','dialed') THEN 1 ELSE 0 END) as in_progress")
                ->selectRaw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed")
                ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed")
                ->first();
        }

        return response()->json([
            'data' => [
                'totals' => [
                    'pending' => (int) ($totals->pending ?? 0),
                    'in_progress' => (int) ($totals->in_progress ?? 0),
                    'completed' => (int) ($totals->completed ?? 0),
                    'failed' => (int) ($totals->failed ?? 0),
                ],
            ],
        ]);
    }

    public function queue(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $campaign = Campaign::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        $queueItems = DialQueueItem::query()
            ->with(['lead:id,full_name,phone', 'assignedAgentEntity:id,company_number'])
            ->where('tenant_id', $tenant->id)
            ->where('campaign_id', $campaign->id)
            ->orderByDesc('priority')
            ->orderBy('enqueued_at')
            ->paginate((int) $request->integer('per_page', 50));

        return response()->json([
            'data' => collect($queueItems->items())->map(fn (DialQueueItem $item) => [
                'id' => $item->id,
                'status' => $item->status,
                'priority' => $item->priority,
                'attempt_count' => $item->attempt_count,
                'max_attempts' => $item->max_attempts,
                'failure_reason' => $item->failure_reason,
                'available_at' => $item->available_at?->toISOString(),
                'enqueued_at' => $item->enqueued_at?->toISOString(),
                'processed_at' => $item->processed_at?->toISOString(),
                'lead' => $item->lead ? [
                    'id' => $item->lead->id,
                    'full_name' => $item->lead->full_name,
                    'phone' => $item->lead->phone,
                ] : null,
                'agent' => $item->assignedAgentEntity ? [
                    'id' => $item->assignedAgentEntity->id,
                    'name' => $item->assignedAgentEntity->company_number,
                ] : null,
            ])->values(),
            'meta' => [
                'pagination' => [
                    'total' => $queueItems->total(),
                    'per_page' => $queueItems->perPage(),
                    'current_page' => $queueItems->currentPage(),
                    'last_page' => $queueItems->lastPage(),
                ],
            ],
        ]);
    }

    public function messageReport(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $campaign = Campaign::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        $runId = $request->filled('run_id') ? (string) $request->query('run_id') : null;
        $run = CampaignRun::query()
            ->where('tenant_id', $tenant->id)
            ->where('campaign_id', $campaign->id)
            ->when($runId, fn ($q) => $q->where('id', $runId))
            ->latest('created_at')
            ->first();

        if (! $run) {
            return response()->json([
                'data' => [
                    'campaign_id' => $campaign->id,
                    'campaign_run_id' => null,
                    'channel' => null,
                    'entries' => [],
                ],
            ]);
        }

        $settings = (array) ($campaign->settings ?? []);
        $channel = (string) (($run->metadata['channel'] ?? null) ?: (($settings['channel'] ?? null) ?: ''));
        $channelFilter = in_array($channel, ['sms', 'whatsapp'], true) ? $channel : null;

        $leadIds = $this->resolveCampaignLeadIds($campaign);
        $leads = $leadIds === []
            ? collect()
            : Lead::query()
                ->where('tenant_id', $tenant->id)
                ->whereIn('id', $leadIds)
                ->get(['id', 'full_name', 'phone'])
                ->values();

        $numbers = $leads
            ->map(fn (Lead $lead) => (string) $lead->phone)
            ->filter()
            ->unique()
            ->values();

        $threadsByNumber = $numbers->isEmpty()
            ? collect()
            : MessageThread::query()
                ->where('tenant_id', $tenant->id)
                ->whereIn('counterparty_number', $numbers->all())
                ->when($channelFilter, fn ($q) => $q->where('channel', $channelFilter))
                ->orderByDesc('updated_at')
                ->get(['id', 'channel', 'counterparty_number'])
                ->keyBy('counterparty_number');

        $threadIds = $threadsByNumber->values()->pluck('id')->filter()->unique()->values();

        $outbound = Message::query()
            ->where('tenant_id', $tenant->id)
            ->where('direction', 'outbound')
            ->where('metadata->campaign_run_id', (string) $run->id)
            ->orderBy('sent_at')
            ->get(['id', 'thread_id', 'body', 'status', 'sent_at', 'delivered_at', 'provider_message_id']);

        $outboundById = $outbound->keyBy('id');
        $startedAt = $run->started_at ?: $outbound->min('sent_at');

        $outboundTimelineByLead = $leadIds === []
            ? collect()
            : LeadTimelineItem::query()
                ->where('tenant_id', $tenant->id)
                ->whereIn('lead_id', $leadIds)
                ->where('related_type', 'message')
                ->where('metadata->bulk_batch_id', (string) $run->id)
                ->where('metadata->direction', 'outbound')
                ->orderBy('occurred_at')
                ->get(['id', 'lead_id', 'related_id', 'content', 'metadata', 'occurred_at'])
                ->groupBy('lead_id');

        $inboundByThread = $threadIds->isEmpty()
            ? collect()
            : Message::query()
                ->where('tenant_id', $tenant->id)
                ->where('direction', 'inbound')
                ->whereIn('thread_id', $threadIds->all())
                ->when($startedAt, fn ($q) => $q->where('sent_at', '>=', $startedAt))
                ->orderBy('sent_at')
                ->get(['id', 'thread_id', 'body', 'status', 'sent_at'])
                ->groupBy('thread_id');

        $duplicateNumbers = $leads
            ->groupBy(fn (Lead $lead) => (string) $lead->phone)
            ->map(fn ($items) => $items->count())
            ->filter(fn (int $count, string $phone) => $phone !== '' && $count > 1);

        $entries = $leads->map(function (Lead $lead) use ($threadsByNumber, $outboundTimelineByLead, $outboundById, $inboundByThread): array {
            $phone = (string) $lead->phone;
            $thread = $phone !== '' ? $threadsByNumber->get($phone) : null;
            $threadId = $thread?->id ? (string) $thread->id : null;

            $timelineItems = $outboundTimelineByLead->get((string) $lead->id) ?? collect();
            $outboundMessages = $timelineItems
                ->map(function (LeadTimelineItem $item) use ($outboundById): array {
                    $message = $item->related_id ? $outboundById->get((string) $item->related_id) : null;
                    $metadata = (array) ($item->metadata ?? []);

                    return [
                        'id' => (string) ($message?->id ?? $item->id),
                        'status' => (string) ($message?->status ?? ($metadata['status'] ?? 'unknown')),
                        'body' => (string) ($message?->body ?? $item->content ?? ''),
                        'sent_at' => ($message?->sent_at ?? $item->occurred_at)?->toISOString(),
                        'delivered_at' => $message?->delivered_at?->toISOString(),
                        'read_at' => $message?->read_at?->toISOString(),
                        'provider_message_id' => (string) ($message?->provider_message_id ?? ($metadata['provider_message_id'] ?? '')),
                    ];
                })
                ->values()
                ->all();

            $inboundMessages = $threadId
                ? ($inboundByThread->get($threadId) ?? collect())
                    ->map(fn (Message $message) => [
                        'id' => $message->id,
                        'status' => $message->status,
                        'body' => $message->body,
                        'sent_at' => $message->sent_at?->toISOString(),
                    ])
                    ->values()
                    ->all()
                : [];

            return [
                'thread_id' => $threadId,
                'channel' => (string) ($thread?->channel ?? ''),
                'counterparty_number' => $phone,
                'lead' => [
                    'id' => $lead->id,
                    'full_name' => $lead->full_name,
                    'phone' => $lead->phone,
                ],
                'outbound' => $outboundMessages,
                'inbound' => $inboundMessages,
                'counts' => [
                    'outbound' => count($outboundMessages),
                    'inbound' => count($inboundMessages),
                ],
            ];
        })->values();

        return response()->json([
            'data' => [
                'campaign_id' => $campaign->id,
                'campaign_run_id' => $run->id,
                'channel' => $channelFilter ?: $channel,
                'summary' => [
                    'leads' => $leads->count(),
                    'unique_numbers' => $numbers->count(),
                    'duplicate_numbers' => $duplicateNumbers->count(),
                ],
                'entries' => $entries,
            ],
        ]);
    }

    private function seedQueue(Campaign $campaign, CampaignRun $run): void
    {
        $leadQuery = Lead::query()
            ->where('leads.tenant_id', $campaign->tenant_id)
            ->whereIn('leads.status', ['new', 'follow_up', 'contacted'])
            ->orderBy('leads.updated_at');
        $leadListIds = collect((array) (($campaign->settings ?? [])['lead_list_ids'] ?? []))
            ->filter()
            ->values();
        if ($leadListIds->isNotEmpty()) {
            $leadQuery->join('lead_list_lead', function ($join) use ($campaign, $leadListIds): void {
                $join->on('lead_list_lead.lead_id', '=', 'leads.id')
                    ->where('lead_list_lead.tenant_id', '=', $campaign->tenant_id)
                    ->whereIn('lead_list_lead.lead_list_id', $leadListIds->all());
            });
        }
        $leadIds = $leadQuery
            ->select('leads.id')
            ->distinct()
            ->limit(max(1, $campaign->queue_size * 10))
            ->pluck('leads.id');

        $now = now();
        $rows = $leadIds->map(fn (string $leadId) => [
            'id' => (string) Str::uuid(),
            'tenant_id' => $campaign->tenant_id,
            'campaign_id' => $campaign->id,
            'campaign_run_id' => $run->id,
            'lead_id' => $leadId,
            'priority' => $campaign->priority,
            'attempt_count' => 0,
            'max_attempts' => $campaign->retry_limit + 1,
            'status' => 'pending',
            'available_at' => $now,
            'enqueued_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        if ($rows !== []) {
            DialQueueItem::query()->insert($rows);
        }

        $run->total_items = count($rows);
        $run->queued_items = count($rows);
        $run->save();
    }

    private function serializeCampaign(Campaign $campaign): array
    {
        $settings = (array) ($campaign->settings ?? []);
        return [
            'id' => $campaign->id,
            'name' => $campaign->name,
            'type' => $campaign->type,
            'status' => $campaign->status,
            'lead_list_name' => $campaign->lead_list_name ?? '',
            'schedule_window' => $campaign->schedule_window ?? '',
            'retry_limit' => $campaign->retry_limit,
            'queue_size' => $campaign->queue_size,
            'calls_per_minute' => $campaign->calls_per_minute,
            'auto_pause_when_no_agents' => (bool) $campaign->auto_pause_when_no_agents,
            'priority' => $campaign->priority,
            'preferred_provider_account_id' => $campaign->preferred_provider_account_id,
            'lead_list_ids' => collect((array) (($campaign->settings ?? [])['lead_list_ids'] ?? []))
                ->filter()
                ->values()
                ->all(),
            'channel' => $settings['channel'] ?? null,
            'message_content' => $settings['message_content'] ?? null,
            'message_template_key' => $settings['message_template_key'] ?? null,
            'provider_account_id' => $settings['provider_account_id'] ?? null,
            'updated_at' => $campaign->updated_at?->toISOString(),
        ];
    }

    private function dispatchMessageCampaign(Campaign $campaign, CampaignRun $run, string $channel): void
    {
        $settings = (array) ($campaign->settings ?? []);
        $content = (string) ($settings['message_content'] ?? '');
        $templateKey = (string) ($settings['message_template_key'] ?? '');
        $variables = (array) ($settings['message_variables'] ?? []);
        $providerAccountId = (string) ($settings['provider_account_id'] ?? $campaign->preferred_provider_account_id ?? '');

        $baseVariables = [
            'campaign_name' => (string) $campaign->name,
            'campaign' => [
                'id' => (string) $campaign->id,
                'name' => (string) $campaign->name,
            ],
        ];
        if ($run->started_by) {
            $user = \App\Models\User::query()->where('id', $run->started_by)->first();
            $baseVariables['agent_name'] = (string) ($user?->name ?? '');
            $baseVariables['agent'] = [
                'id' => (string) $run->started_by,
                'name' => (string) ($user?->name ?? ''),
            ];
        }
        $variables = array_merge($baseVariables, $variables);

        $leadIds = $this->resolveCampaignLeadIds($campaign);
        $run->total_items = count($leadIds);
        $run->queued_items = count($leadIds);
        $run->metadata = array_merge((array) ($run->metadata ?? []), [
            'channel' => $channel,
            'campaign_id' => $campaign->id,
            'provider_account_id' => $providerAccountId !== '' ? $providerAccountId : null,
        ]);
        $run->save();

        $perMinute = max(1, (int) ($campaign->calls_per_minute ?? 60));
        $spacingSeconds = 60 / $perMinute;
        $now = now();

        foreach (array_values($leadIds) as $index => $leadId) {
            $delaySeconds = (int) floor($index * $spacingSeconds);
            \App\Jobs\DispatchOutboundMessageJob::dispatch(
                tenantId: $campaign->tenant_id,
                leadId: (string) $leadId,
                channel: $channel,
                content: $content,
                templateKey: $templateKey,
                variables: $variables,
                sentByUserId: $run->started_by,
                bulkBatchId: $run->id,
                providerAccountId: $providerAccountId !== '' ? $providerAccountId : null,
                campaignId: $campaign->id,
                campaignRunId: $run->id,
            )->delay($now->copy()->addSeconds($delaySeconds));
        }
    }

    private function recoverMessageCampaignIfStuck(Campaign $campaign, CampaignRun $run, string $channel): void
    {
        if ($run->status !== 'running') {
            return;
        }
        if ((int) ($run->queued_items ?? 0) <= 0) {
            return;
        }

        $runId = (string) $run->id;

        $pendingJobs = (int) DB::table('jobs')
            ->where('payload', 'like', '%DispatchOutboundMessageJob%')
            ->where('payload', 'like', '%'.$runId.'%')
            ->count();
        if ($pendingJobs > 0) {
            return;
        }

        $processedLeadIds = LeadTimelineItem::query()
            ->where('tenant_id', $campaign->tenant_id)
            ->where('related_type', 'message')
            ->where('metadata->bulk_batch_id', $runId)
            ->where('metadata->direction', 'outbound')
            ->pluck('lead_id')
            ->map(fn ($id) => (string) $id)
            ->filter()
            ->unique()
            ->values();

        $leadIds = collect($this->resolveCampaignLeadIds($campaign))
            ->map(fn ($id) => (string) $id)
            ->filter()
            ->unique()
            ->values();

        $missingLeadIds = $leadIds
            ->reject(fn (string $leadId) => $processedLeadIds->contains($leadId))
            ->values();

        if ($missingLeadIds->isEmpty()) {
            if ((int) $run->queued_items !== 0) {
                $run->queued_items = 0;
                $run->last_tick_at = now();
                $run->save();
            }
            return;
        }

        $settings = (array) ($campaign->settings ?? []);
        $content = (string) ($settings['message_content'] ?? '');
        $templateKey = (string) ($settings['message_template_key'] ?? '');
        $variables = (array) ($settings['message_variables'] ?? []);
        $providerAccountId = (string) ($settings['provider_account_id'] ?? $campaign->preferred_provider_account_id ?? '');

        $baseVariables = [
            'campaign_name' => (string) $campaign->name,
            'campaign' => [
                'id' => (string) $campaign->id,
                'name' => (string) $campaign->name,
            ],
        ];
        if ($run->started_by) {
            $user = \App\Models\User::query()->where('id', $run->started_by)->first();
            $baseVariables['agent_name'] = (string) ($user?->name ?? '');
            $baseVariables['agent'] = [
                'id' => (string) $run->started_by,
                'name' => (string) ($user?->name ?? ''),
            ];
        }
        $variables = array_merge($baseVariables, $variables);

        DB::transaction(function () use ($campaign, $run, $leadIds, $missingLeadIds): void {
            $run->total_items = (int) $leadIds->count();
            $run->queued_items = (int) $missingLeadIds->count();
            $run->last_tick_at = now();
            $run->save();
        });

        try {
            Log::info('Message campaign recovery dispatched missing jobs.', [
                'tenant_id' => (string) $campaign->tenant_id,
                'campaign_id' => (string) $campaign->id,
                'campaign_run_id' => (string) $run->id,
                'channel' => $channel,
                'missing' => (int) $missingLeadIds->count(),
                'processed' => (int) $processedLeadIds->count(),
                'pending_jobs' => $pendingJobs,
            ]);
        } catch (\Throwable) {
        }

        $perMinute = max(1, (int) ($campaign->calls_per_minute ?? 60));
        $spacingSeconds = 60 / $perMinute;
        $now = now();

        foreach ($missingLeadIds->values() as $index => $leadId) {
            $delaySeconds = (int) floor($index * $spacingSeconds);
            \App\Jobs\DispatchOutboundMessageJob::dispatch(
                tenantId: $campaign->tenant_id,
                leadId: (string) $leadId,
                channel: $channel,
                content: $content,
                templateKey: $templateKey,
                variables: $variables,
                sentByUserId: $run->started_by,
                bulkBatchId: $run->id,
                providerAccountId: $providerAccountId !== '' ? $providerAccountId : null,
                campaignId: $campaign->id,
                campaignRunId: $run->id,
            )->delay($now->copy()->addSeconds($delaySeconds));
        }
    }

    private function resolveCampaignLeadIds(Campaign $campaign): array
    {
        $isMessageCampaign = in_array($campaign->type, ['sms', 'whatsapp', 'outreach'], true);
        $leadQuery = Lead::query()
            ->where('leads.tenant_id', $campaign->tenant_id)
            ->where('leads.is_dnc', false)
            ->whereNotNull('leads.phone')
            ->where('leads.phone', '!=', '')
            ->when(
                ! $isMessageCampaign,
                fn ($q) => $q->whereIn('leads.status', ['new', 'follow_up', 'contacted'])
            )
            ->orderBy('leads.updated_at');
        $leadListIds = collect((array) (($campaign->settings ?? [])['lead_list_ids'] ?? []))
            ->filter()
            ->values();
        if ($leadListIds->isNotEmpty()) {
            $leadQuery->join('lead_list_lead', function ($join) use ($campaign, $leadListIds): void {
                $join->on('lead_list_lead.lead_id', '=', 'leads.id')
                    ->where('lead_list_lead.tenant_id', '=', $campaign->tenant_id)
                    ->whereIn('lead_list_lead.lead_list_id', $leadListIds->all());
            });
        }

        $limit = $isMessageCampaign
            ? 10000
            : max(1, (int) $campaign->queue_size);

        return $leadQuery
            ->select('leads.id')
            ->distinct()
            ->limit($limit)
            ->pluck('leads.id')
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();
    }

    /**
     * @return array{ok: bool, code?: string, message?: string}
     */
    private function validateMessagingProvider(string $tenantId, Campaign $campaign, string $channel): array
    {
        $settings = (array) ($campaign->settings ?? []);
        $providerAccountId = (string) ($settings['provider_account_id'] ?? $campaign->preferred_provider_account_id ?? '');
        if ($providerAccountId === '') {
            return ['ok' => false, 'code' => 'PROVIDER_REQUIRED', 'message' => 'Select a provider/connection for this campaign.'];
        }

        $provider = \App\Models\ProviderAccount::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $providerAccountId)
            ->first();
        if (! $provider) {
            return [
                'ok' => false,
                'code' => 'PROVIDER_MISSING',
                'message' => 'Selected provider does not exist for this tenant (provider_account_id='.$providerAccountId.').',
            ];
        }
        if ($provider->status !== 'active') {
            return [
                'ok' => false,
                'code' => 'PROVIDER_INACTIVE',
                'message' => 'Selected provider is not active (provider_account_id='.$providerAccountId.', status='.$provider->status.'). Click "Test Connection" in Providers to activate it.',
            ];
        }

        $credentials = (array) ($provider->credentials_encrypted ?? []);
        if ($channel === 'sms') {
            $sid = (string) ($credentials['account_sid'] ?? '');
            $token = (string) ($credentials['auth_token'] ?? '');
            $from = (string) ($credentials['from_number'] ?? '');
            if ($sid === '' || $token === '') {
                return ['ok' => false, 'code' => 'SMS_PROVIDER_CREDENTIALS_MISSING', 'message' => 'Twilio account_sid/auth_token are missing in provider credentials.'];
            }
            if ($from === '') {
                return ['ok' => false, 'code' => 'SMS_FROM_MISSING', 'message' => 'SMS From is missing. Set from_number in provider credentials.'];
            }
        } else {
            $metaToken = (string) ($credentials['meta_access_token'] ?? '');
            $metaPhoneNumberId = (string) ($credentials['phone_number_id'] ?? '');
            if ($metaToken !== '' || $metaPhoneNumberId !== '') {
                if ($metaToken === '' || $metaPhoneNumberId === '') {
                    return ['ok' => false, 'code' => 'META_CREDENTIALS_MISSING', 'message' => 'Meta WhatsApp access_token or phone_number_id is missing in provider credentials.'];
                }
            } else {
                $sid = (string) ($credentials['account_sid'] ?? '');
                $token = (string) ($credentials['auth_token'] ?? '');
                $waFrom = (string) ($credentials['whatsapp_from'] ?? $credentials['from_number'] ?? '');
                if ($sid === '' || $token === '') {
                    return ['ok' => false, 'code' => 'WHATSAPP_PROVIDER_CREDENTIALS_MISSING', 'message' => 'Twilio account_sid/auth_token are missing in provider credentials.'];
                }
                if ($waFrom === '') {
                    return ['ok' => false, 'code' => 'WHATSAPP_FROM_MISSING', 'message' => 'WhatsApp From is missing. Set whatsapp_from in provider credentials (example: whatsapp:+14155238886).'];
                }
            }
        }

        return ['ok' => true];
    }

    private function serializeRun(CampaignRun $run): array
    {
        return [
            'id' => $run->id,
            'status' => $run->status,
            'total_items' => $run->total_items,
            'queued_items' => $run->queued_items,
            'completed_items' => $run->completed_items,
            'failed_items' => $run->failed_items,
            'retried_items' => $run->retried_items,
            'calls_dispatched' => $run->calls_dispatched,
            'calls_connected' => $run->calls_connected,
            'calls_failed' => $run->calls_failed,
            'calls_per_minute' => $run->calls_per_minute,
            'started_at' => $run->started_at?->toISOString(),
            'paused_at' => $run->paused_at?->toISOString(),
            'stopped_at' => $run->stopped_at?->toISOString(),
            'last_tick_at' => $run->last_tick_at?->toISOString(),
        ];
    }
}
