<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AiReceptionEvent;
use App\Models\CallSession;
use App\Models\Contact;
use App\Models\ContactPhone;
use App\Models\ContactProjectLink;
use App\Models\Extension;
use App\Models\GovernanceDrill;
use App\Models\GraphAvailabilityQuery;
use App\Models\GraphBooking;
use App\Models\LegalHold;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\MessageThread;
use App\Models\MessagingOptOut;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\ReportSnapshot;
use App\Models\RetentionPolicy;
use App\Models\RingGroup;
use App\Models\RingGroupMember;
use App\Models\TenantSetting;
use App\Models\VoicemailMessage;
use App\Models\WhatsappOptIn;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowRun;
use App\Services\AuditLogger;
use App\Services\Integrations\Part3AdapterManager;
use App\Services\Messaging\MessageTemplateRenderer;
use App\Support\FeatureFlags;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CorePhaseOneController extends Controller
{
    private const SMS_OPT_OUT_KEYWORDS = [
        'STOP',
        'STOPALL',
        'UNSUBSCRIBE',
        'CANCEL',
        'END',
        'QUIT',
    ];

    private const SMS_OPT_IN_KEYWORDS = [
        'START',
        'YES',
        'UNSTOP',
    ];

    public function __construct(
        private readonly FeatureFlags $featureFlags,
        private readonly AuditLogger $auditLogger,
        private readonly Part3AdapterManager $part3AdapterManager,
        private readonly MessageTemplateRenderer $templateRenderer,
    ) {
    }

    public function contactsIndex(Request $request): JsonResponse
    {
        if ($disabled = $this->ensureEnabled('phase1_contact_directory')) {
            return $disabled;
        }

        $tenant = $request->attributes->get('tenant');
        $contacts = Contact::query()
            ->with('phones')
            ->where('tenant_id', $tenant->id)
            ->when($request->filled('q'), fn ($q) => $q->where('display_name', 'like', '%'.(string) $request->input('q').'%'))
            ->latest('created_at')
            ->paginate((int) $request->integer('per_page', 25));

        return response()->json([
            'success' => true,
            'data' => $contacts->items(),
            'meta' => [
                'pagination' => [
                    'total' => $contacts->total(),
                    'per_page' => $contacts->perPage(),
                    'current_page' => $contacts->currentPage(),
                    'last_page' => $contacts->lastPage(),
                ],
            ],
        ]);
    }

    public function contactsStore(Request $request): JsonResponse
    {
        if ($disabled = $this->ensureEnabled('phase1_contact_directory')) {
            return $disabled;
        }

        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'display_name' => ['required', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'role' => ['nullable', 'string', 'max:80'],
            'tags' => ['nullable', 'array', 'max:20'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'phones' => ['nullable', 'array', 'max:10'],
            'phones.*.e164' => ['required_with:phones', 'regex:/^\+[1-9]\d{7,14}$/'],
            'phones.*.label' => ['nullable', 'string', 'max:50'],
            'phones.*.is_primary' => ['nullable', 'boolean'],
        ]);

        $contact = Contact::query()->create([
            'tenant_id' => $tenant->id,
            'display_name' => $validated['display_name'],
            'company' => $validated['company'] ?? null,
            'role' => $validated['role'] ?? null,
            'tags' => $validated['tags'] ?? [],
            'notes' => $validated['notes'] ?? null,
        ]);

        foreach ((array) ($validated['phones'] ?? []) as $phone) {
            ContactPhone::query()->create([
                'tenant_id' => $tenant->id,
                'contact_id' => $contact->id,
                'e164' => (string) $phone['e164'],
                'label' => $phone['label'] ?? null,
                'is_primary' => (bool) ($phone['is_primary'] ?? false),
            ]);
        }

        $contact->load('phones');

        return response()->json([
            'success' => true,
            'data' => $contact,
        ], 201);
    }

    public function contactsUpdate(Request $request, string $id): JsonResponse
    {
        if ($disabled = $this->ensureEnabled('phase1_contact_directory')) {
            return $disabled;
        }

        $tenant = $request->attributes->get('tenant');
        $contact = Contact::query()->where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();
        $validated = $request->validate([
            'display_name' => ['sometimes', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'role' => ['nullable', 'string', 'max:80'],
            'tags' => ['nullable', 'array', 'max:20'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $contact->fill($validated);
        $contact->save();

        return response()->json([
            'success' => true,
            'data' => $contact,
        ]);
    }

    public function projectsIndex(Request $request): JsonResponse
    {
        if ($disabled = $this->ensureEnabled('phase1_project_context')) {
            return $disabled;
        }

        $tenant = $request->attributes->get('tenant');
        $projects = Project::query()
            ->with(['ownerContact', 'assignments.engineer'])
            ->where('tenant_id', $tenant->id)
            ->when($request->filled('status'), fn ($q) => $q->where('status', (string) $request->input('status')))
            ->latest('created_at')
            ->paginate((int) $request->integer('per_page', 25));

        return response()->json([
            'success' => true,
            'data' => $projects->items(),
            'meta' => [
                'pagination' => [
                    'total' => $projects->total(),
                    'per_page' => $projects->perPage(),
                    'current_page' => $projects->currentPage(),
                    'last_page' => $projects->lastPage(),
                ],
            ],
        ]);
    }

    public function projectsStore(Request $request): JsonResponse
    {
        if ($disabled = $this->ensureEnabled('phase1_project_context')) {
            return $disabled;
        }

        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'site_address' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:active,on_hold,completed,canceled'],
            'priority' => ['nullable', 'in:low,normal,high,urgent'],
            'owner_contact_id' => ['nullable', 'uuid'],
        ]);

        $project = Project::query()->create([
            'tenant_id' => $tenant->id,
            ...$validated,
            'status' => $validated['status'] ?? 'active',
            'priority' => $validated['priority'] ?? 'normal',
        ]);

        return response()->json([
            'success' => true,
            'data' => $project,
        ], 201);
    }

    public function projectsUpdate(Request $request, string $id): JsonResponse
    {
        if ($disabled = $this->ensureEnabled('phase1_project_context')) {
            return $disabled;
        }

        $tenant = $request->attributes->get('tenant');
        $project = Project::query()->where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'site_address' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'in:active,on_hold,completed,canceled'],
            'priority' => ['sometimes', 'in:low,normal,high,urgent'],
            'owner_contact_id' => ['nullable', 'uuid'],
        ]);

        $project->fill($validated);
        $project->save();

        return response()->json([
            'success' => true,
            'data' => $project,
        ]);
    }

    public function projectLinkContact(Request $request, string $id): JsonResponse
    {
        if ($disabled = $this->ensureEnabled('phase1_project_context')) {
            return $disabled;
        }

        $tenant = $request->attributes->get('tenant');
        $project = Project::query()->where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();
        $validated = $request->validate([
            'contact_id' => ['required', 'uuid'],
            'relationship_type' => ['required', 'string', 'max:50'],
            'is_primary' => ['nullable', 'boolean'],
        ]);

        $link = ContactProjectLink::query()->firstOrCreate([
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'contact_id' => (string) $validated['contact_id'],
            'relationship_type' => (string) $validated['relationship_type'],
        ], [
            'is_primary' => (bool) ($validated['is_primary'] ?? false),
        ]);

        return response()->json([
            'success' => true,
            'data' => $link,
        ], 201);
    }

    public function projectAssignEngineer(Request $request, string $id): JsonResponse
    {
        if ($disabled = $this->ensureEnabled('phase1_project_context')) {
            return $disabled;
        }

        $tenant = $request->attributes->get('tenant');
        $project = Project::query()->where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();
        $validated = $request->validate([
            'engineer_id' => ['required', 'uuid'],
            'role' => ['nullable', 'in:primary,secondary,observer'],
        ]);

        $assignment = ProjectAssignment::query()->create([
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'engineer_id' => (string) $validated['engineer_id'],
            'role' => (string) ($validated['role'] ?? 'primary'),
            'active_from' => now(),
        ]);

        return response()->json([
            'success' => true,
            'data' => $assignment,
        ], 201);
    }

    public function interactionContext(Request $request): JsonResponse
    {
        if ($disabled = $this->ensureEnabled('phase1_interaction_context')) {
            return $disabled;
        }

        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'phone' => ['required', 'regex:/^\+[1-9]\d{7,14}$/'],
        ]);

        $phone = (string) $validated['phone'];
        $contactPhone = ContactPhone::query()
            ->where('tenant_id', $tenant->id)
            ->where('e164', $phone)
            ->first();

        $contact = $contactPhone?->contact;
        $projects = collect();
        if ($contact) {
            $projectIds = ContactProjectLink::query()
                ->where('tenant_id', $tenant->id)
                ->where('contact_id', $contact->id)
                ->pluck('project_id');
            $projects = Project::query()->whereIn('id', $projectIds)->get();
        }

        $recentCalls = CallSession::query()
            ->where('tenant_id', $tenant->id)
            ->where(function ($q) use ($phone) {
                $q->where('from_number', $phone)->orWhere('to_number', $phone);
            })
            ->latest('created_at')
            ->limit(10)
            ->get();

        $thread = MessageThread::query()
            ->where('tenant_id', $tenant->id)
            ->where('counterparty_number', $phone)
            ->latest('last_message_at')
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'phone' => $phone,
                'contact' => $contact,
                'projects' => $projects,
                'recent_calls' => $recentCalls,
                'message_thread' => $thread,
            ],
        ]);
    }

    public function extensionsIndex(Request $request): JsonResponse
    {
        if ($disabled = $this->ensureEnabled('phase1_voice_runtime')) {
            return $disabled;
        }

        $tenant = $request->attributes->get('tenant');
        $items = Extension::query()
            ->where('tenant_id', $tenant->id)
            ->orderBy('extension')
            ->get();

        return response()->json(['success' => true, 'data' => $items]);
    }

    public function extensionsStore(Request $request): JsonResponse
    {
        if ($disabled = $this->ensureEnabled('phase1_voice_runtime')) {
            return $disabled;
        }

        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'extension' => ['required', 'regex:/^\d{2,6}$/'],
            'target_type' => ['required', 'in:user,ring_group,external_number'],
            'target_id' => ['nullable', 'uuid'],
            'is_reserved' => ['nullable', 'boolean'],
        ]);

        $extension = Extension::query()->create([
            'tenant_id' => $tenant->id,
            ...$validated,
            'is_reserved' => (bool) ($validated['is_reserved'] ?? false),
        ]);

        return response()->json(['success' => true, 'data' => $extension], 201);
    }

    public function ringGroupsIndex(Request $request): JsonResponse
    {
        if ($disabled = $this->ensureEnabled('phase1_voice_runtime')) {
            return $disabled;
        }

        $tenant = $request->attributes->get('tenant');
        $groups = RingGroup::query()
            ->with('members')
            ->where('tenant_id', $tenant->id)
            ->orderBy('name')
            ->get();

        return response()->json(['success' => true, 'data' => $groups]);
    }

    public function ringGroupsStore(Request $request): JsonResponse
    {
        if ($disabled = $this->ensureEnabled('phase1_voice_runtime')) {
            return $disabled;
        }

        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'strategy' => ['nullable', 'in:simultaneous,sequential,longest_idle'],
            'ring_timeout_seconds' => ['nullable', 'integer', 'between:5,120'],
            'max_queue_seconds' => ['nullable', 'integer', 'between:30,1800'],
            'max_retries' => ['nullable', 'integer', 'between:0,5'],
            'active' => ['nullable', 'boolean'],
            'members' => ['nullable', 'array', 'max:30'],
            'members.*.target_type' => ['required_with:members', 'in:user,extension,external_number'],
            'members.*.target_id' => ['nullable', 'uuid'],
            'members.*.external_number' => ['nullable', 'regex:/^\+[1-9]\d{7,14}$/'],
            'members.*.priority' => ['nullable', 'integer', 'min:1', 'max:999'],
        ]);

        $group = RingGroup::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $validated['name'],
            'strategy' => $validated['strategy'] ?? 'simultaneous',
            'ring_timeout_seconds' => $validated['ring_timeout_seconds'] ?? 20,
            'max_queue_seconds' => $validated['max_queue_seconds'] ?? 120,
            'max_retries' => $validated['max_retries'] ?? 1,
            'active' => (bool) ($validated['active'] ?? true),
        ]);

        foreach ((array) ($validated['members'] ?? []) as $member) {
            RingGroupMember::query()->create([
                'tenant_id' => $tenant->id,
                'ring_group_id' => $group->id,
                'target_type' => $member['target_type'],
                'target_id' => $member['target_id'] ?? null,
                'external_number' => $member['external_number'] ?? null,
                'priority' => (int) ($member['priority'] ?? 100),
            ]);
        }

        $group->load('members');

        return response()->json(['success' => true, 'data' => $group], 201);
    }

    public function voicemailIndex(Request $request): JsonResponse
    {
        if ($disabled = $this->ensureEnabled('phase1_voicemail')) {
            return $disabled;
        }

        $tenant = $request->attributes->get('tenant');
        $items = VoicemailMessage::query()
            ->where('tenant_id', $tenant->id)
            ->latest('created_at')
            ->paginate((int) $request->integer('per_page', 25));

        return response()->json([
            'success' => true,
            'data' => $items->items(),
            'meta' => [
                'pagination' => [
                    'total' => $items->total(),
                    'per_page' => $items->perPage(),
                    'current_page' => $items->currentPage(),
                    'last_page' => $items->lastPage(),
                ],
            ],
        ]);
    }

    public function voicemailStore(Request $request): JsonResponse
    {
        if ($disabled = $this->ensureEnabled('phase1_voicemail')) {
            return $disabled;
        }

        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'call_session_id' => ['nullable', 'uuid'],
            'contact_id' => ['nullable', 'uuid'],
            'project_id' => ['nullable', 'uuid'],
            'from_number' => ['nullable', 'regex:/^\+[1-9]\d{7,14}$/'],
            'to_number' => ['nullable', 'regex:/^\+[1-9]\d{7,14}$/'],
            'storage_url' => ['nullable', 'url', 'max:500'],
            'transcript' => ['nullable', 'string', 'max:10000'],
            'status' => ['nullable', 'in:captured,transcribed,reviewed,closed'],
            'metadata' => ['nullable', 'array', 'max:20'],
        ]);

        $vm = VoicemailMessage::query()->create([
            'tenant_id' => $tenant->id,
            ...$validated,
            'status' => $validated['status'] ?? 'captured',
            'metadata' => $validated['metadata'] ?? [],
        ]);

        return response()->json(['success' => true, 'data' => $vm], 201);
    }

    public function threadsIndex(Request $request): JsonResponse
    {
        if ($disabled = $this->ensureEnabled('phase1_sms_inbox')) {
            return $disabled;
        }

        $tenant = $request->attributes->get('tenant');
        $channel = (string) $request->input('channel', 'sms');
        if (! in_array($channel, ['sms', 'whatsapp'], true)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_CHANNEL',
                    'message' => 'channel must be sms or whatsapp.',
                ],
            ], 422);
        }

        $threads = MessageThread::query()
            ->where('tenant_id', $tenant->id)
            ->where('channel', $channel)
            ->when($request->filled('status'), fn ($q) => $q->where('status', (string) $request->input('status')))
            ->when($request->filled('priority'), fn ($q) => $q->where('priority', (string) $request->input('priority')))
            ->latest('last_message_at')
            ->paginate((int) $request->integer('per_page', 25));

        return response()->json([
            'success' => true,
            'data' => $threads->items(),
            'meta' => [
                'pagination' => [
                    'total' => $threads->total(),
                    'per_page' => $threads->perPage(),
                    'current_page' => $threads->currentPage(),
                    'last_page' => $threads->lastPage(),
                ],
            ],
        ]);
    }

    public function threadsMessagesIndex(Request $request, string $threadId): JsonResponse
    {
        if ($disabled = $this->ensureEnabled('phase1_sms_inbox')) {
            return $disabled;
        }

        $tenant = $request->attributes->get('tenant');
        $thread = MessageThread::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $threadId)
            ->firstOrFail();

        $messages = \App\Models\Message::query()
            ->where('tenant_id', $tenant->id)
            ->where('thread_id', $thread->id)
            ->orderByDesc('sent_at')
            ->paginate((int) $request->integer('per_page', 50));

        // Return messages in chronological order (earliest first) for chat UI
        $items = collect($messages->items())->reverse()->values();

        return response()->json([
            'success' => true,
            'data' => $items,
            'meta' => [
                'pagination' => [
                    'total' => $messages->total(),
                    'per_page' => $messages->perPage(),
                    'current_page' => $messages->currentPage(),
                    'last_page' => $messages->lastPage(),
                ],
            ],
        ]);
    }

    public function threadsSendMessage(Request $request, string $threadId): JsonResponse
    {
        if ($disabled = $this->ensureEnabled('phase1_sms_inbox')) {
            return $disabled;
        }

        $tenant = $request->attributes->get('tenant');
        $thread = MessageThread::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $threadId)
            ->firstOrFail();
        if ($thread->channel === 'whatsapp' && ($disabled = $this->ensureEnabled('phase2_whatsapp'))) {
            return $disabled;
        }
        if (
            $thread->channel === 'sms'
            && $thread->counterparty_number
            && MessagingOptOut::query()
                ->where('tenant_id', $tenant->id)
                ->where('phone', $thread->counterparty_number)
                ->where('channel', 'sms')
                ->where('opted_out', true)
                ->exists()
        ) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'SMS_OPTED_OUT',
                    'message' => 'Counterparty is opted-out from SMS messaging.',
                ],
            ], 422);
        }
        if ($thread->channel === 'whatsapp') {
            $optIn = WhatsappOptIn::query()
                ->where('tenant_id', $tenant->id)
                ->where('counterparty_number', $thread->counterparty_number)
                ->first();
            if ($optIn && $optIn->opted_in === false) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'WHATSAPP_OPT_IN_REQUIRED',
                        'message' => 'Counterparty is opted-out from WhatsApp messaging.',
                    ],
                ], 422);
            }
        }

        $validated = $request->validate([
            'body' => ['required_without:template_key', 'string', 'max:1600'],
            'template_key' => ['required_without:body', 'string', 'max:80'],
            'variables' => ['nullable', 'array', 'max:50'],
            'media' => ['nullable', 'array', 'max:10'],
        ]);

        $body = (string) ($validated['body'] ?? '');
        $templateKey = (string) ($validated['template_key'] ?? '');
        if ($templateKey !== '') {
            $template = MessageTemplate::query()
                ->where('tenant_id', $tenant->id)
                ->where('channel', $thread->channel)
                ->where('key', $templateKey)
                ->where('is_active', true)
                ->first();

            if (! $template) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'TEMPLATE_NOT_FOUND',
                        'message' => 'Message template not found.',
                    ],
                ], 404);
            }

            $variables = array_merge([
                'thread' => [
                    'id' => $thread->id,
                    'channel' => $thread->channel,
                    'counterparty_number' => $thread->counterparty_number,
                ],
            ], (array) ($validated['variables'] ?? []));

            $body = $this->templateRenderer->render((string) $template->body, $variables);
            $body = trim($body);
            if ($body === '') {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'TEMPLATE_RENDER_EMPTY',
                        'message' => 'Rendered template content is empty.',
                    ],
                ], 422);
            }
        }

        $providerMessageId = '';
        $status = '';
        $mode = 'live';
        $errorMessage = null;
        $statusCode = null;

        $providerTypes = $thread->channel === 'whatsapp' ? ['meta_whatsapp', 'twilio'] : ['twilio'];
        $provider = \App\Models\ProviderAccount::query()
                ->where('tenant_id', $tenant->id)
                ->whereIn('provider_type', $providerTypes)
                ->where('status', 'active')
                // Prefer meta_whatsapp over twilio for whatsapp channels
                ->orderByRaw("CASE WHEN provider_type = 'meta_whatsapp' THEN 1 ELSE 2 END")
                ->latest('created_at')
                ->first();

        if (! $provider) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'PROVIDER_REQUIRED',
                    'message' => 'No active Twilio provider is configured for messaging.',
                ],
            ], 422);
        }

        $providerCredentials = (array) ($provider->credentials_encrypted ?? []);
        $statusCallbackUrl = rtrim((string) config('app.url'), '/').'/api/v1/webhooks/twilio/message-status';
        $result = $thread->channel === 'sms'
            ? app(\App\Services\Messaging\SmsService::class)->send((string) $thread->counterparty_number, $body, $statusCallbackUrl, $providerCredentials)
            : app(\App\Services\Messaging\WhatsAppService::class)->send((string) $thread->counterparty_number, $body, $statusCallbackUrl, $providerCredentials);

        if (($result['ok'] ?? false) !== true) {
            $errorMessage = (string) ($result['error'] ?? 'Message delivery failed.');
            $statusCode = $result['status_code'] ?? null;
            $status = 'failed';
        } else {
            $providerMessageId = (string) ($result['provider_message_id'] ?? '');
            $status = (string) ($result['status'] ?? 'queued');
            $mode = 'live';
        }

        $message = Message::query()->create([
            'tenant_id' => $tenant->id,
            'thread_id' => $thread->id,
            'direction' => 'outbound',
            'status' => $status !== '' ? $status : 'queued',
            'body' => $body,
            'media' => $validated['media'] ?? [],
            'sent_by_user_id' => $request->user()?->id,
            'provider_message_id' => $providerMessageId,
            'metadata' => array_filter([
                'mode' => $mode,
                'channel' => $thread->channel,
                'template_key' => $templateKey !== '' ? $templateKey : null,
                'error' => $errorMessage,
                'status_code' => $statusCode,
            ], fn ($value) => $value !== null && $value !== ''),
            'sent_at' => now(),
            'delivered_at' => in_array($status, ['delivered', 'read'], true) ? now() : null,
        ]);

        if ($status === 'failed') {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'MESSAGE_SEND_FAILED',
                    'message' => $errorMessage ?: 'Message delivery failed.',
                ],
                'data' => $message,
            ], 422);
        }

        $thread->last_message_at = now();
        if (! $thread->first_outbound_at) {
            $thread->first_outbound_at = now();
        }
        $thread->save();

        return response()->json(['success' => true, 'data' => $message], 202);
    }

    public function threadUpdate(Request $request, string $threadId): JsonResponse
    {
        if ($disabled = $this->ensureEnabled('phase1_sms_inbox')) {
            return $disabled;
        }

        $tenant = $request->attributes->get('tenant');
        $thread = MessageThread::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $threadId)
            ->firstOrFail();

        $validated = $request->validate([
            'assigned_user_id' => ['nullable', 'uuid'],
            'status' => ['nullable', 'in:open,pending,resolved,closed'],
            'priority' => ['nullable', 'in:low,normal,high,urgent'],
        ]);

        $thread->fill($validated);
        $thread->save();

        return response()->json(['success' => true, 'data' => $thread]);
    }

    public function inboxSlaPolicyShow(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        return response()->json([
            'success' => true,
            'data' => $this->resolveInboxSlaPolicy($tenant->id),
        ]);
    }

    public function inboxSlaPolicyUpsert(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'first_response_minutes' => ['nullable', 'integer', 'min:1', 'max:10080'],
            'resolution_minutes' => ['nullable', 'integer', 'min:1', 'max:43200'],
        ]);

        $setting = TenantSetting::query()->firstOrCreate(
            ['tenant_id' => $tenant->id],
            ['metadata' => []]
        );
        $metadata = (array) ($setting->metadata ?? []);
        $policy = array_merge($this->resolveInboxSlaPolicy($tenant->id), $validated);
        $metadata['inbox_sla'] = $policy;
        $setting->metadata = $metadata;
        $setting->save();

        return response()->json([
            'success' => true,
            'data' => $policy,
        ]);
    }

    public function inboundSmsMock(Request $request): JsonResponse
    {
        if ($disabled = $this->ensureEnabled('phase1_sms_inbox')) {
            return $disabled;
        }

        return $this->ingestInboundMessageMock($request, 'sms');
    }

    public function inboundWhatsappMock(Request $request): JsonResponse
    {
        if ($disabled = $this->ensureEnabled('phase2_whatsapp')) {
            return $disabled;
        }

        return $this->ingestInboundMessageMock($request, 'whatsapp');
    }

    public function whatsappOptInUpdate(Request $request): JsonResponse
    {
        if ($disabled = $this->ensureEnabled('phase2_whatsapp')) {
            return $disabled;
        }

        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'tenant_id' => ['required', 'uuid'],
            'counterparty_number' => ['required', 'regex:/^\+[1-9]\d{7,14}$/'],
            'opted_in' => ['required', 'boolean'],
            'source' => ['nullable', 'string', 'max:40'],
        ]);
        if ($invalidTenant = $this->ensureTenantMatch($tenant->id, (string) $validated['tenant_id'])) {
            return $invalidTenant;
        }

        $optIn = WhatsappOptIn::query()->updateOrCreate([
            'tenant_id' => $tenant->id,
            'counterparty_number' => (string) $validated['counterparty_number'],
        ], [
            'opted_in' => (bool) $validated['opted_in'],
            'source' => (string) ($validated['source'] ?? 'manual_update'),
            'last_changed_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'data' => $optIn,
        ]);
    }

    public function teamsNotifyMock(Request $request): JsonResponse
    {
        if ($disabled = $this->ensureEnabled('phase1_teams_notifications')) {
            return $disabled;
        }

        $validated = $request->validate([
            'tenant_id' => ['required', 'uuid'],
            'title' => ['required', 'string', 'max:120'],
            'message' => ['required', 'string', 'max:2000'],
            'severity' => ['nullable', 'in:info,warning,error'],
        ]);

        $data = $this->part3AdapterManager->adapter()->notifyTeams($validated);

        return response()->json(['success' => true, 'data' => $data], 202);
    }

    public function teamsApprovalsIndex(Request $request): JsonResponse
    {
        if ($disabled = $this->ensureEnabled('phase1_teams_notifications')) {
            return $disabled;
        }

        $tenant = $request->attributes->get('tenant');
        $items = DB::table('teams_approval_requests')
            ->where('tenant_id', $tenant->id)
            ->when($request->filled('status'), fn ($q) => $q->where('status', (string) $request->input('status')))
            ->orderByDesc('created_at')
            ->paginate((int) $request->integer('per_page', 25));

        return response()->json([
            'data' => $items->items(),
            'meta' => [
                'pagination' => [
                    'total' => $items->total(),
                    'per_page' => $items->perPage(),
                    'current_page' => $items->currentPage(),
                    'last_page' => $items->lastPage(),
                ],
            ],
        ]);
    }

    public function teamsApprovalCreate(Request $request): JsonResponse
    {
        if ($disabled = $this->ensureEnabled('phase1_teams_notifications')) {
            return $disabled;
        }

        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'tenant_id' => ['required', 'uuid'],
            'title' => ['required', 'string', 'max:120'],
            'message' => ['required', 'string', 'max:4000'],
            'severity' => ['nullable', 'in:info,warning,error'],
            'expires_in_minutes' => ['nullable', 'integer', 'between:5,10080'],
            'metadata' => ['nullable', 'array'],
        ]);
        if ($invalidTenant = $this->ensureTenantMatch($tenant->id, (string) $validated['tenant_id'])) {
            return $invalidTenant;
        }

        $id = (string) Str::uuid();
        $token = Str::random(64);
        $expiresAt = isset($validated['expires_in_minutes'])
            ? now()->addMinutes((int) $validated['expires_in_minutes'])
            : null;

        $baseUrl = rtrim((string) config('app.url'), '/');
        $respondBase = $baseUrl.'/api/webhooks/teams/approvals/'.$id.'/respond?token='.urlencode($token);
        $approveUrl = $respondBase.'&action=approve';
        $rejectUrl = $respondBase.'&action=reject';

        $cardPayload = [
            'type' => 'AdaptiveCard',
            '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
            'version' => '1.4',
            'body' => [
                ['type' => 'TextBlock', 'size' => 'Medium', 'weight' => 'Bolder', 'text' => (string) $validated['title']],
                ['type' => 'TextBlock', 'wrap' => true, 'text' => (string) $validated['message']],
            ],
            'actions' => [
                ['type' => 'Action.OpenUrl', 'title' => 'Approve', 'url' => $approveUrl],
                ['type' => 'Action.OpenUrl', 'title' => 'Reject', 'url' => $rejectUrl],
            ],
        ];

        DB::table('teams_approval_requests')->insert([
            'id' => $id,
            'tenant_id' => $tenant->id,
            'created_by_user_id' => $request->user()?->id,
            'responded_by_user_id' => null,
            'token' => $token,
            'title' => (string) $validated['title'],
            'message' => (string) $validated['message'],
            'severity' => (string) ($validated['severity'] ?? 'info'),
            'status' => 'pending',
            'responded_at' => null,
            'expires_at' => $expiresAt,
            'card_payload' => json_encode($cardPayload, JSON_UNESCAPED_SLASHES),
            'response_payload' => null,
            'metadata' => isset($validated['metadata']) ? json_encode($validated['metadata'], JSON_UNESCAPED_SLASHES) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $delivery = $this->part3AdapterManager->adapter()->notifyTeams([
            'tenant_id' => $tenant->id,
            'title' => 'Approval Required: '.(string) $validated['title'],
            'message' => (string) $validated['message']."\n\nApprove: {$approveUrl}\nReject: {$rejectUrl}",
            'severity' => (string) ($validated['severity'] ?? 'info'),
        ]);

        return response()->json([
            'data' => [
                'id' => $id,
                'tenant_id' => $tenant->id,
                'status' => 'pending',
                'expires_at' => $expiresAt?->toISOString(),
                'approve_url' => $approveUrl,
                'reject_url' => $rejectUrl,
                'card_payload' => $cardPayload,
                'teams_delivery' => $delivery,
            ],
        ], 201);
    }

    public function teamsApprovalRespond(Request $request, string $id): JsonResponse
    {
        if ($disabled = $this->ensureEnabled('phase1_teams_notifications')) {
            return $disabled;
        }

        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'action' => ['required', 'in:approve,reject'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $record = DB::table('teams_approval_requests')
            ->where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->first();
        if (! $record) {
            return response()->json(['message' => 'Approval request not found.'], 404);
        }

        if ($record->status !== 'pending') {
            return response()->json(['data' => ['status' => (string) $record->status]], 409);
        }

        if ($record->expires_at !== null && now()->gt($record->expires_at)) {
            DB::table('teams_approval_requests')->where('id', $id)->update([
                'status' => 'expired',
                'updated_at' => now(),
            ]);

            return response()->json(['data' => ['status' => 'expired']], 409);
        }

        $responsePayload = [
            'action' => (string) $validated['action'],
            'note' => $validated['note'] ?? null,
            'channel' => 'in_app',
            'responded_by' => $request->user()?->id,
            'responded_at' => now()->toISOString(),
        ];

        DB::table('teams_approval_requests')->where('id', $id)->update([
            'status' => (string) $validated['action'] === 'approve' ? 'approved' : 'rejected',
            'responded_by_user_id' => $request->user()?->id,
            'responded_at' => now(),
            'response_payload' => json_encode($responsePayload, JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ]);

        return response()->json([
            'data' => [
                'id' => $id,
                'status' => (string) $validated['action'] === 'approve' ? 'approved' : 'rejected',
                'response' => $responsePayload,
            ],
        ]);
    }

    public function teamsApprovalWebhookRespond(Request $request, string $id): JsonResponse
    {
        $token = (string) ($request->query('token', '') ?: $request->input('token', ''));
        $action = (string) ($request->query('action', '') ?: $request->input('action', ''));
        $note = $request->query('note', null) ?? $request->input('note', null);

        if ($token === '' || ! in_array($action, ['approve', 'reject'], true)) {
            return response()->json(['message' => 'Invalid approval response.'], 422);
        }

        $record = DB::table('teams_approval_requests')->where('id', $id)->first();
        if (! $record) {
            return response()->json(['message' => 'Approval request not found.'], 404);
        }

        if (! hash_equals((string) $record->token, $token)) {
            return response()->json(['message' => 'Invalid token.'], 403);
        }

        if ($record->status !== 'pending') {
            return response()->json(['data' => ['status' => (string) $record->status]], 409);
        }

        if ($record->expires_at !== null && now()->gt($record->expires_at)) {
            DB::table('teams_approval_requests')->where('id', $id)->update([
                'status' => 'expired',
                'updated_at' => now(),
            ]);

            return response()->json(['data' => ['status' => 'expired']], 409);
        }

        $responsePayload = [
            'action' => $action,
            'note' => is_string($note) ? $note : null,
            'channel' => 'webhook_link',
            'responded_by' => null,
            'responded_at' => now()->toISOString(),
        ];

        DB::table('teams_approval_requests')->where('id', $id)->update([
            'status' => $action === 'approve' ? 'approved' : 'rejected',
            'responded_by_user_id' => null,
            'responded_at' => now(),
            'response_payload' => json_encode($responsePayload, JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ]);

        return response()->json([
            'data' => [
                'id' => $id,
                'status' => $action === 'approve' ? 'approved' : 'rejected',
                'response' => $responsePayload,
            ],
        ]);
    }

    public function aiReceptionHandleMock(Request $request): JsonResponse
    {
        if ($disabled = $this->ensureEnabled('phase2_ai_receptionist')) {
            return $disabled;
        }

        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'tenant_id' => ['required', 'uuid'],
            'caller_number' => ['required', 'regex:/^\+[1-9]\d{7,14}$/'],
            'transcript' => ['required', 'string', 'max:3000'],
            'confidence_threshold' => ['nullable', 'numeric', 'between:0,1'],
        ]);
        if ($invalidTenant = $this->ensureTenantMatch($tenant->id, (string) $validated['tenant_id'])) {
            return $invalidTenant;
        }

        $data = $this->part3AdapterManager->adapter()->handleAiReception($validated);
        $event = AiReceptionEvent::query()->create([
            'tenant_id' => $tenant->id,
            'caller_number' => (string) $validated['caller_number'],
            'transcript' => (string) $validated['transcript'],
            'confidence_threshold' => isset($validated['confidence_threshold']) ? round((float) $validated['confidence_threshold'], 2) : null,
            'decision' => (string) ($data['decision'] ?? 'capture_message'),
            'confidence' => isset($data['confidence']) ? round((float) $data['confidence'], 2) : null,
            'captured_message' => isset($data['captured_message']) ? (string) $data['captured_message'] : null,
            'recommended_route' => isset($data['recommended_route']) ? (string) $data['recommended_route'] : null,
            'provider_mode' => (string) ($data['mode'] ?? 'mock'),
            'metadata' => [
                'request' => $validated,
                'response' => $data,
            ],
            'processed_at' => now(),
        ]);

        if (($data['decision'] ?? null) === 'auto_route') {
            $session = CallSession::query()
                ->where('tenant_id', $tenant->id)
                ->where(function (Builder $query) use ($validated) {
                    $query->where('from_number', (string) $validated['caller_number'])
                        ->orWhere('to_number', (string) $validated['caller_number']);
                })
                ->latest('created_at')
                ->first();
            if ($session) {
                $session->runtime_state = 'routed';
                $session->routed_to = (string) ($data['recommended_route'] ?? 'ring_group:default');
                $session->routing_confidence = isset($data['confidence']) ? (float) $data['confidence'] : null;
                $session->save();
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                ...$data,
                'event_id' => $event->id,
            ],
        ], 202);
    }

    public function graphAvailabilityMock(Request $request): JsonResponse
    {
        if ($disabled = $this->ensureEnabled('phase2_graph_scheduling')) {
            return $disabled;
        }

        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'tenant_id' => ['required', 'uuid'],
            'duration_minutes' => ['required', 'integer', 'between:15,120'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after:from'],
        ]);
        if ($invalidTenant = $this->ensureTenantMatch($tenant->id, (string) $validated['tenant_id'])) {
            return $invalidTenant;
        }

        $data = $this->part3AdapterManager->adapter()->graphAvailability($validated);
        $query = GraphAvailabilityQuery::query()->create([
            'tenant_id' => $tenant->id,
            'duration_minutes' => (int) $validated['duration_minutes'],
            'window_from' => (string) $validated['from'],
            'window_to' => (string) $validated['to'],
            'slots' => (array) ($data['slots'] ?? []),
            'provider_mode' => (string) ($data['mode'] ?? 'mock'),
            'queried_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                ...$data,
                'query_id' => $query->id,
            ],
        ]);
    }

    public function graphBookMock(Request $request): JsonResponse
    {
        if ($disabled = $this->ensureEnabled('phase2_graph_scheduling')) {
            return $disabled;
        }

        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'tenant_id' => ['required', 'uuid'],
            'availability_query_id' => ['nullable', 'uuid'],
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after:start'],
            'attendee_email' => ['required', 'email'],
            'subject' => ['required', 'string', 'max:150'],
        ]);
        if ($invalidTenant = $this->ensureTenantMatch($tenant->id, (string) $validated['tenant_id'])) {
            return $invalidTenant;
        }

        $data = $this->part3AdapterManager->adapter()->graphBook($validated);
        $booking = GraphBooking::query()->create([
            'tenant_id' => $tenant->id,
            'availability_query_id' => $validated['availability_query_id'] ?? null,
            'external_booking_id' => (string) ($data['booking_id'] ?? ''),
            'calendar_event_id' => (string) ($data['calendar_event_id'] ?? ''),
            'attendee_email' => (string) $validated['attendee_email'],
            'subject' => (string) $validated['subject'],
            'start_at' => (string) $validated['start'],
            'end_at' => (string) $validated['end'],
            'confirmation_sent' => (bool) ($data['confirmation_sent'] ?? true),
            'provider_mode' => (string) ($data['mode'] ?? 'mock'),
            'metadata' => [
                'request' => $validated,
                'response' => $data,
            ],
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                ...$data,
                'record_id' => $booking->id,
            ],
        ], 201);
    }

    public function graphBookingsIndex(Request $request): JsonResponse
    {
        if ($disabled = $this->ensureEnabled('phase2_graph_scheduling')) {
            return $disabled;
        }

        $tenant = $request->attributes->get('tenant');
        $items = GraphBooking::query()
            ->where('tenant_id', $tenant->id)
            ->when($request->filled('status'), fn ($q) => $q->where('status', (string) $request->input('status')))
            ->when($request->filled('attendee_email'), fn ($q) => $q->where('attendee_email', (string) $request->input('attendee_email')))
            ->latest('created_at')
            ->paginate((int) $request->integer('per_page', 25));

        return response()->json([
            'data' => $items->items(),
            'meta' => [
                'pagination' => [
                    'total' => $items->total(),
                    'per_page' => $items->perPage(),
                    'current_page' => $items->currentPage(),
                    'last_page' => $items->lastPage(),
                ],
            ],
        ]);
    }

    public function graphBookingShow(Request $request, string $id): JsonResponse
    {
        if ($disabled = $this->ensureEnabled('phase2_graph_scheduling')) {
            return $disabled;
        }

        $tenant = $request->attributes->get('tenant');
        $booking = GraphBooking::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        return response()->json(['data' => $booking]);
    }

    public function graphBookingUpdate(Request $request, string $id): JsonResponse
    {
        if ($disabled = $this->ensureEnabled('phase2_graph_scheduling')) {
            return $disabled;
        }

        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'tenant_id' => ['required', 'uuid'],
            'start' => ['nullable', 'date'],
            'end' => ['nullable', 'date'],
            'subject' => ['nullable', 'string', 'max:150'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        if ($invalidTenant = $this->ensureTenantMatch($tenant->id, (string) $validated['tenant_id'])) {
            return $invalidTenant;
        }

        $booking = GraphBooking::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        if ($booking->status === 'canceled') {
            return response()->json([
                'error' => [
                    'code' => 'BOOKING_CANCELED',
                    'message' => 'Canceled bookings cannot be modified.',
                ],
            ], 409);
        }

        $newStart = isset($validated['start']) ? \Illuminate\Support\Carbon::parse((string) $validated['start']) : $booking->start_at;
        $newEnd = isset($validated['end']) ? \Illuminate\Support\Carbon::parse((string) $validated['end']) : $booking->end_at;
        if (($validated['start'] ?? null) !== null && ($validated['end'] ?? null) === null) {
            return response()->json(['error' => ['code' => 'END_REQUIRED', 'message' => 'end is required when start is provided.']], 422);
        }
        if (($validated['end'] ?? null) !== null && ($validated['start'] ?? null) === null) {
            return response()->json(['error' => ['code' => 'START_REQUIRED', 'message' => 'start is required when end is provided.']], 422);
        }

        if ($newStart && $newEnd) {
            $conflict = GraphBooking::query()
                ->where('tenant_id', $tenant->id)
                ->where('attendee_email', $booking->attendee_email)
                ->where('status', '!=', 'canceled')
                ->where('id', '!=', $booking->id)
                ->where(function ($q) use ($newStart, $newEnd) {
                    $q->where('start_at', '<', $newEnd)
                        ->where('end_at', '>', $newStart);
                })
                ->first();

            if ($conflict) {
                return response()->json([
                    'error' => [
                        'code' => 'BOOKING_CONFLICT',
                        'message' => 'The attendee already has a booking during the requested time window.',
                    ],
                ], 409);
            }
        }

        $payload = [
            'tenant_id' => $tenant->id,
            'booking_id' => (string) ($booking->external_booking_id ?? ''),
            'calendar_event_id' => (string) ($booking->calendar_event_id ?? ''),
            'attendee_email' => (string) $booking->attendee_email,
            'start' => $newStart?->toISOString(),
            'end' => $newEnd?->toISOString(),
            'subject' => (string) (($validated['subject'] ?? null) ?? $booking->subject),
            'reason' => $validated['reason'] ?? null,
        ];

        $adapterResponse = $this->part3AdapterManager->adapter()->graphBookingUpdate($payload);

        $previousStart = $booking->start_at?->toISOString();
        $previousEnd = $booking->end_at?->toISOString();
        $updatedStart = $payload['start'];
        $updatedEnd = $payload['end'];
        $isReschedule = $previousStart !== $updatedStart || $previousEnd !== $updatedEnd;

        $booking->start_at = $newStart;
        $booking->end_at = $newEnd;
        if (isset($validated['subject'])) {
            $booking->subject = (string) $validated['subject'];
        }
        $booking->status = $isReschedule ? 'rescheduled' : 'confirmed';
        $booking->metadata = array_merge((array) ($booking->metadata ?? []), [
            'lifecycle' => array_merge((array) (($booking->metadata['lifecycle'] ?? null) ?: []), [
                [
                    'type' => $isReschedule ? 'reschedule' : 'update',
                    'at' => now()->toISOString(),
                    'reason' => $validated['reason'] ?? null,
                    'request' => $payload,
                    'response' => $adapterResponse,
                ],
            ]),
        ]);
        $booking->save();

        return response()->json([
            'data' => [
                'booking' => $booking,
                'provider' => $adapterResponse,
            ],
        ]);
    }

    public function graphBookingCancel(Request $request, string $id): JsonResponse
    {
        if ($disabled = $this->ensureEnabled('phase2_graph_scheduling')) {
            return $disabled;
        }

        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'tenant_id' => ['required', 'uuid'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        if ($invalidTenant = $this->ensureTenantMatch($tenant->id, (string) $validated['tenant_id'])) {
            return $invalidTenant;
        }

        $booking = GraphBooking::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        if ($booking->status === 'canceled') {
            return response()->json(['data' => ['booking' => $booking]]);
        }

        $payload = [
            'tenant_id' => $tenant->id,
            'booking_id' => (string) ($booking->external_booking_id ?? ''),
            'calendar_event_id' => (string) ($booking->calendar_event_id ?? ''),
            'attendee_email' => (string) $booking->attendee_email,
            'reason' => $validated['reason'] ?? null,
        ];

        $adapterResponse = $this->part3AdapterManager->adapter()->graphBookingCancel($payload);

        $booking->status = 'canceled';
        $booking->canceled_at = now();
        $booking->metadata = array_merge((array) ($booking->metadata ?? []), [
            'lifecycle' => array_merge((array) (($booking->metadata['lifecycle'] ?? null) ?: []), [
                [
                    'type' => 'cancel',
                    'at' => now()->toISOString(),
                    'reason' => $validated['reason'] ?? null,
                    'request' => $payload,
                    'response' => $adapterResponse,
                ],
            ]),
        ]);
        $booking->save();

        return response()->json([
            'data' => [
                'booking' => $booking,
                'provider' => $adapterResponse,
            ],
        ]);
    }

    public function workflowsIndex(Request $request): JsonResponse
    {
        if ($disabled = $this->ensureEnabled('phase3_workflows')) {
            return $disabled;
        }

        $tenant = $request->attributes->get('tenant');
        $items = WorkflowDefinition::query()
            ->where('tenant_id', $tenant->id)
            ->latest('updated_at')
            ->paginate((int) $request->integer('per_page', 25));

        return response()->json([
            'success' => true,
            'data' => $items->items(),
            'meta' => [
                'pagination' => [
                    'total' => $items->total(),
                    'per_page' => $items->perPage(),
                    'current_page' => $items->currentPage(),
                    'last_page' => $items->lastPage(),
                ],
            ],
        ]);
    }

    public function workflowsStore(Request $request): JsonResponse
    {
        if ($disabled = $this->ensureEnabled('phase3_workflows')) {
            return $disabled;
        }

        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'tenant_id' => ['required', 'uuid'],
            'workflow_key' => ['required', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:120'],
            'trigger_type' => ['nullable', 'in:manual,call_completed,inbound_message,voicemail_captured'],
            'description' => ['nullable', 'string', 'max:2000'],
            'active' => ['nullable', 'boolean'],
            'steps' => ['nullable', 'array', 'max:30'],
        ]);
        if ($invalidTenant = $this->ensureTenantMatch($tenant->id, (string) $validated['tenant_id'])) {
            return $invalidTenant;
        }

        $workflow = WorkflowDefinition::query()->updateOrCreate([
            'tenant_id' => $tenant->id,
            'workflow_key' => (string) $validated['workflow_key'],
        ], [
            'name' => (string) $validated['name'],
            'trigger_type' => (string) ($validated['trigger_type'] ?? 'manual'),
            'description' => $validated['description'] ?? null,
            'active' => (bool) ($validated['active'] ?? true),
            'steps' => $validated['steps'] ?? [],
        ]);

        return response()->json([
            'success' => true,
            'data' => $workflow,
        ], 201);
    }

    public function workflowRunMock(Request $request): JsonResponse
    {
        if ($disabled = $this->ensureEnabled('phase3_workflows')) {
            return $disabled;
        }

        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'tenant_id' => ['required', 'uuid'],
            'workflow_key' => ['required', 'string', 'max:80'],
            'input' => ['nullable', 'array'],
        ]);
        if ($invalidTenant = $this->ensureTenantMatch($tenant->id, (string) $validated['tenant_id'])) {
            return $invalidTenant;
        }

        $workflow = WorkflowDefinition::query()
            ->where('tenant_id', $tenant->id)
            ->where('workflow_key', (string) $validated['workflow_key'])
            ->first();
        if ($workflow && $workflow->active === false) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'WORKFLOW_DISABLED',
                    'message' => 'Workflow exists but is currently disabled.',
                ],
            ], 422);
        }

        $run = WorkflowRun::query()->create([
            'tenant_id' => $tenant->id,
            'workflow_definition_id' => $workflow?->id,
            'workflow_key' => (string) $validated['workflow_key'],
            'status' => 'queued',
            'provider_mode' => 'mock',
            'input' => (array) ($validated['input'] ?? []),
            'started_at' => now(),
        ]);

        $data = $this->part3AdapterManager->adapter()->runWorkflow($validated);
        $run->status = (string) ($data['status'] ?? 'completed');
        $run->provider_mode = (string) ($data['mode'] ?? 'mock');
        $run->output = (array) ($data['output'] ?? []);
        $run->finished_at = now();
        $run->save();

        return response()->json([
            'success' => true,
            'data' => [
                ...$data,
                'run_record_id' => $run->id,
            ],
        ], 202);
    }

    public function unifiedReportingMock(Request $request): JsonResponse
    {
        if ($disabled = $this->ensureEnabled('phase3_unified_reporting')) {
            return $disabled;
        }

        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'tenant_id' => ['required', 'uuid'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);
        if ($invalidTenant = $this->ensureTenantMatch($tenant->id, (string) $validated['tenant_id'])) {
            return $invalidTenant;
        }

        $from = isset($validated['from']) ? (string) $validated['from'] : null;
        $to = isset($validated['to']) ? (string) $validated['to'] : null;
        $kpis = $this->buildUnifiedKpis($tenant->id, $from, $to);
        $ai = $this->buildUnifiedAiKpis($tenant->id, $from, $to);

        $adapterData = $this->part3AdapterManager->adapter()->unifiedReporting($validated);
        $adapterMode = (string) ($adapterData['mode'] ?? 'computed');
        $mode = in_array($adapterMode, ['live', 'mock', 'computed'], true) ? $adapterMode : 'computed';
        $data = [
            'mode' => $mode,
            'kpis' => $mode === 'live' ? (array) ($adapterData['kpis'] ?? $kpis) : $kpis,
            'ai' => $mode === 'live' ? (array) ($adapterData['ai'] ?? $ai) : $ai,
            'filters' => [
                'tenant_id' => $tenant->id,
                'from' => $from,
                'to' => $to,
            ],
        ];

        $snapshot = ReportSnapshot::query()->create([
            'tenant_id' => $tenant->id,
            'from_date' => $from,
            'to_date' => $to,
            'kpis' => (array) $data['kpis'],
            'ai' => (array) $data['ai'],
            'provider_mode' => $data['mode'],
            'generated_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                ...$data,
                'snapshot_id' => $snapshot->id,
            ],
        ]);
    }

    public function governanceRetentionMock(Request $request): JsonResponse
    {
        if ($disabled = $this->ensureEnabled('phase3_governance')) {
            return $disabled;
        }

        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'tenant_id' => ['required', 'uuid'],
            'retention_days' => ['required', 'integer', 'between:30,3650'],
            'pii_redaction_enabled' => ['nullable', 'boolean'],
            'audit_export_email' => ['nullable', 'email'],
        ]);
        if ($invalidTenant = $this->ensureTenantMatch($tenant->id, (string) $validated['tenant_id'])) {
            return $invalidTenant;
        }

        $data = $this->part3AdapterManager->adapter()->applyRetentionPolicy($validated);
        $policy = RetentionPolicy::query()->updateOrCreate([
            'tenant_id' => $tenant->id,
        ], [
            'retention_days' => (int) $validated['retention_days'],
            'pii_redaction_enabled' => (bool) ($validated['pii_redaction_enabled'] ?? false),
            'audit_export_email' => $validated['audit_export_email'] ?? null,
            'provider_mode' => (string) ($data['mode'] ?? 'mock'),
            'effective_at' => (string) ($data['effective_at'] ?? now()->toISOString()),
            'metadata' => [
                'policy_id' => $data['policy_id'] ?? null,
                'request' => $validated,
            ],
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                ...$data,
                'record_id' => $policy->id,
            ],
        ], 202);
    }

    public function governanceRetentionShow(Request $request): JsonResponse
    {
        if ($disabled = $this->ensureEnabled('phase3_governance')) {
            return $disabled;
        }

        $tenant = $request->attributes->get('tenant');
        $policy = RetentionPolicy::query()->where('tenant_id', $tenant->id)->first();

        return response()->json([
            'success' => true,
            'data' => $policy,
        ]);
    }

    public function governanceDrillMock(Request $request): JsonResponse
    {
        if ($disabled = $this->ensureEnabled('phase3_governance')) {
            return $disabled;
        }

        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'tenant_id' => ['required', 'uuid'],
            'scenario' => ['required', 'in:region_outage,provider_outage,db_restore,webhook_backlog'],
        ]);
        if ($invalidTenant = $this->ensureTenantMatch($tenant->id, (string) $validated['tenant_id'])) {
            return $invalidTenant;
        }

        $drill = GovernanceDrill::query()->create([
            'tenant_id' => $tenant->id,
            'scenario' => (string) $validated['scenario'],
            'status' => 'queued',
            'provider_mode' => 'mock',
            'started_at' => now(),
        ]);

        $data = $this->part3AdapterManager->adapter()->runGovernanceDrill($validated);
        $drill->status = (string) ($data['status'] ?? 'completed');
        $drill->provider_mode = (string) ($data['mode'] ?? 'mock');
        $drill->rto_minutes = isset($data['rto_minutes']) ? (int) $data['rto_minutes'] : null;
        $drill->rpo_minutes = isset($data['rpo_minutes']) ? (int) $data['rpo_minutes'] : null;
        $drill->results = $data;
        $drill->completed_at = now();
        $drill->save();

        return response()->json([
            'success' => true,
            'data' => [
                ...$data,
                'record_id' => $drill->id,
            ],
        ], 202);
    }

    public function governanceDrillsIndex(Request $request): JsonResponse
    {
        if ($disabled = $this->ensureEnabled('phase3_governance')) {
            return $disabled;
        }

        $tenant = $request->attributes->get('tenant');
        $items = GovernanceDrill::query()
            ->where('tenant_id', $tenant->id)
            ->latest('created_at')
            ->paginate((int) $request->integer('per_page', 25));

        return response()->json([
            'success' => true,
            'data' => $items->items(),
            'meta' => [
                'pagination' => [
                    'total' => $items->total(),
                    'per_page' => $items->perPage(),
                    'current_page' => $items->currentPage(),
                    'last_page' => $items->lastPage(),
                ],
            ],
        ]);
    }

    public function governanceLegalHoldsIndex(Request $request): JsonResponse
    {
        if ($disabled = $this->ensureEnabled('phase3_governance')) {
            return $disabled;
        }

        $tenant = $request->attributes->get('tenant');
        $items = LegalHold::query()
            ->where('tenant_id', $tenant->id)
            ->when($request->filled('active'), fn ($q) => $q->where('active', (bool) $request->boolean('active')))
            ->latest('created_at')
            ->paginate((int) $request->integer('per_page', 25));

        return response()->json([
            'success' => true,
            'data' => $items->items(),
            'meta' => [
                'pagination' => [
                    'total' => $items->total(),
                    'per_page' => $items->perPage(),
                    'current_page' => $items->currentPage(),
                    'last_page' => $items->lastPage(),
                ],
            ],
        ]);
    }

    public function governanceLegalHoldStore(Request $request): JsonResponse
    {
        if ($disabled = $this->ensureEnabled('phase3_governance')) {
            return $disabled;
        }

        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'tenant_id' => ['required', 'uuid'],
            'scope_type' => ['required', 'in:phone,lead,contact,project'],
            'scope_id' => ['nullable', 'uuid'],
            'phone' => ['nullable', 'regex:/^\+[1-9]\d{7,14}$/'],
            'reason' => ['nullable', 'string', 'max:255'],
            'expires_at' => ['nullable', 'date'],
        ]);
        if ($invalidTenant = $this->ensureTenantMatch($tenant->id, (string) $validated['tenant_id'])) {
            return $invalidTenant;
        }

        if ((string) $validated['scope_type'] === 'phone' && empty($validated['phone'])) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'PHONE_REQUIRED',
                    'message' => 'phone is required when scope_type is phone.',
                ],
            ], 422);
        }

        $hold = LegalHold::query()->create([
            'tenant_id' => $tenant->id,
            'scope_type' => (string) $validated['scope_type'],
            'scope_id' => $validated['scope_id'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'reason' => $validated['reason'] ?? null,
            'active' => true,
            'created_by' => $request->user()?->id,
            'expires_at' => isset($validated['expires_at']) ? (string) $validated['expires_at'] : null,
        ]);

        return response()->json([
            'success' => true,
            'data' => $hold,
        ], 201);
    }

    public function governanceLegalHoldRelease(Request $request, string $id): JsonResponse
    {
        if ($disabled = $this->ensureEnabled('phase3_governance')) {
            return $disabled;
        }

        $tenant = $request->attributes->get('tenant');
        $hold = LegalHold::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        $hold->active = false;
        $hold->save();

        return response()->json([
            'success' => true,
            'data' => $hold,
        ]);
    }

    public function plannedFeatureStatus(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $featureStatus = [
            [
                'feature_key' => 'ai_receptionist_intent_handling',
                'status' => 'implemented',
                'evidence_count' => AiReceptionEvent::query()->where('tenant_id', $tenant->id)->count(),
            ],
            [
                'feature_key' => 'whatsapp_messaging_channel',
                'status' => 'implemented',
                'evidence_count' => MessageThread::query()->where('tenant_id', $tenant->id)->where('channel', 'whatsapp')->count(),
            ],
            [
                'feature_key' => 'microsoft_graph_booking_sync',
                'status' => 'implemented',
                'evidence_count' => GraphBooking::query()->where('tenant_id', $tenant->id)->count(),
            ],
            [
                'feature_key' => 'unified_reporting_layer',
                'status' => 'implemented',
                'evidence_count' => ReportSnapshot::query()->where('tenant_id', $tenant->id)->count(),
            ],
            [
                'feature_key' => 'workflow_automation_engine',
                'status' => 'implemented',
                'evidence_count' => WorkflowRun::query()->where('tenant_id', $tenant->id)->count(),
            ],
            [
                'feature_key' => 'advanced_governance_controls',
                'status' => 'implemented',
                'evidence_count' => GovernanceDrill::query()->where('tenant_id', $tenant->id)->count(),
            ],
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'generated_at' => now()->toISOString(),
                'features' => $featureStatus,
            ],
        ]);
    }

    private function ingestInboundMessageMock(Request $request, string $channel): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => ['required', 'uuid'],
            'from' => ['required', 'regex:/^\+[1-9]\d{7,14}$/'],
            'to' => ['nullable', 'regex:/^\+[1-9]\d{7,14}$/'],
            'body' => ['required', 'string', 'max:1600'],
        ]);

        if ($channel === 'sms') {
            $normalizedBody = strtoupper(trim((string) $validated['body']));
            if ($this->matchesKeyword($normalizedBody, self::SMS_OPT_OUT_KEYWORDS)) {
                MessagingOptOut::query()->updateOrCreate([
                    'tenant_id' => (string) $validated['tenant_id'],
                    'phone' => (string) $validated['from'],
                    'channel' => 'sms',
                ], [
                    'opted_out' => true,
                    'source' => 'inbound_mock',
                    'reason' => $normalizedBody,
                    'last_changed_at' => now(),
                ]);
            } elseif ($this->matchesKeyword($normalizedBody, self::SMS_OPT_IN_KEYWORDS)) {
                MessagingOptOut::query()->updateOrCreate([
                    'tenant_id' => (string) $validated['tenant_id'],
                    'phone' => (string) $validated['from'],
                    'channel' => 'sms',
                ], [
                    'opted_out' => false,
                    'source' => 'inbound_mock',
                    'reason' => $normalizedBody,
                    'last_changed_at' => now(),
                ]);
            }
        }

        $thread = MessageThread::query()->firstOrCreate([
            'tenant_id' => (string) $validated['tenant_id'],
            'channel' => $channel,
            'counterparty_number' => (string) $validated['from'],
        ], [
            'status' => 'open',
            'priority' => 'normal',
            'last_message_at' => now(),
        ]);

        $adapterData = $this->part3AdapterManager->adapter()->ingestInboundMessage($channel, $validated);

        $message = Message::query()->create([
            'tenant_id' => $thread->tenant_id,
            'thread_id' => $thread->id,
            'direction' => 'inbound',
            'status' => 'received',
            'body' => (string) $validated['body'],
            'metadata' => [
                'to' => $validated['to'] ?? null,
                'mode' => $adapterData['mode'] ?? 'mock',
                'channel' => $channel,
            ],
            'sent_at' => now(),
            'delivered_at' => now(),
        ]);

        $thread->last_message_at = now();
        if (! $thread->first_inbound_at) {
            $thread->first_inbound_at = now();
            $sla = $this->resolveInboxSlaPolicy((string) $validated['tenant_id']);
            if (($sla['enabled'] ?? true) === true) {
                $firstResponseMinutes = (int) ($sla['first_response_minutes'] ?? 60);
                $resolutionMinutes = (int) ($sla['resolution_minutes'] ?? 1440);
                $thread->first_response_due_at = now()->addMinutes(max(1, $firstResponseMinutes));
                $thread->resolution_due_at = now()->addMinutes(max(1, $resolutionMinutes));
            }
        }
        $thread->save();

        if ($channel === 'whatsapp') {
            WhatsappOptIn::query()->firstOrCreate([
                'tenant_id' => (string) $validated['tenant_id'],
                'counterparty_number' => (string) $validated['from'],
            ], [
                'opted_in' => true,
                'source' => 'inbound_message',
                'last_changed_at' => now(),
            ]);
        }

        return response()->json(['success' => true, 'data' => $message], 201);
    }

    private function matchesKeyword(string $body, array $keywords): bool
    {
        if ($body === '') {
            return false;
        }

        foreach ($keywords as $keyword) {
            if ($body === $keyword) {
                return true;
            }
        }

        return false;
    }

    private function resolveInboxSlaPolicy(string $tenantId): array
    {
        $setting = TenantSetting::query()->where('tenant_id', $tenantId)->first();
        $metadata = (array) ($setting?->metadata ?? []);
        $policy = (array) ($metadata['inbox_sla'] ?? []);

        return array_merge([
            'enabled' => true,
            'first_response_minutes' => 60,
            'resolution_minutes' => 1440,
        ], $policy);
    }

    private function ensureTenantMatch(string $tenantId, string $requestedTenantId): ?JsonResponse
    {
        if ($tenantId === $requestedTenantId) {
            return null;
        }

        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'TENANT_MISMATCH',
                'message' => 'tenant_id in payload does not match authenticated tenant.',
            ],
        ], 403);
    }

    private function buildUnifiedKpis(string $tenantId, ?string $fromDate, ?string $toDate): array
    {
        $callQuery = CallSession::query()->where('tenant_id', $tenantId);
        $messageQuery = Message::query()->where('tenant_id', $tenantId);
        $voicemailQuery = VoicemailMessage::query()->where('tenant_id', $tenantId);

        if ($fromDate !== null) {
            $callQuery->where('created_at', '>=', $fromDate);
            $messageQuery->where('created_at', '>=', $fromDate);
            $voicemailQuery->where('created_at', '>=', $fromDate);
        }
        if ($toDate !== null) {
            $callQuery->where('created_at', '<=', $toDate);
            $messageQuery->where('created_at', '<=', $toDate);
            $voicemailQuery->where('created_at', '<=', $toDate);
        }

        return [
            'calls_total' => $callQuery->count(),
            'messages_total' => $messageQuery->count(),
            'voicemails_total' => $voicemailQuery->count(),
        ];
    }

    private function buildUnifiedAiKpis(string $tenantId, ?string $fromDate, ?string $toDate): array
    {
        $query = AiReceptionEvent::query()->where('tenant_id', $tenantId);
        if ($fromDate !== null) {
            $query->where('processed_at', '>=', $fromDate);
        }
        if ($toDate !== null) {
            $query->where('processed_at', '<=', $toDate);
        }

        $events = (clone $query)->count();
        $autoRouted = (clone $query)->where('decision', 'auto_route')->count();
        $captured = (clone $query)->where('decision', 'capture_message')->count();

        return [
            'events_total' => $events,
            'auto_route_count' => $autoRouted,
            'captured_message_count' => $captured,
        ];
    }

    private function ensureEnabled(string $flag): ?JsonResponse
    {
        if ($this->featureFlags->enabled($flag)) {
            return null;
        }

        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'FEATURE_DISABLED',
                'message' => "Feature flag '{$flag}' is disabled.",
            ],
        ], 501);
    }

    public function whatsappDebugSendTest(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-Id') ?? $request->user()->tenant_id;
        
        $validated = $request->validate([
            'phone_number' => ['required', 'string'],
            'template_id' => ['required', 'string'],
            'variables' => ['nullable', 'array'],
        ]);

        $provider = \App\Models\ProviderAccount::query()
            ->where('tenant_id', $tenantId)
            ->where('provider_type', 'meta_whatsapp')
            ->where('status', 'active')
            ->latest('created_at')
            ->first();

        if (! $provider) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'PROVIDER_REQUIRED',
                    'message' => 'No active Meta WhatsApp provider is configured.',
                ],
            ], 422);
        }

        $template = \App\Models\MetaWhatsappTemplate::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $validated['template_id'])
            ->first();

        if (! $template) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'TEMPLATE_NOT_FOUND',
                    'message' => 'Meta WhatsApp template not found.',
                ],
            ], 404);
        }

        $metaTemplateService = app(\App\Services\Messaging\MetaTemplateService::class);
        $payload = $metaTemplateService->buildTemplatePayload($template, $validated['phone_number'], $validated['variables'] ?? []);
        $bodyText = $metaTemplateService->buildTemplateTextPreview($template, $validated['variables'] ?? []);

        // Task 3: Logging before sending
        \Illuminate\Support\Facades\Log::channel('single')->info('WhatsApp Debugger: Before sending test message.', [
            'phone_number' => $validated['phone_number'],
            'template_name' => $template->template_name,
            'provider_account_id' => $provider->id,
            'variables' => $validated['variables'] ?? [],
            'payload' => $payload,
        ]);

        $whatsAppService = app(\App\Services\Messaging\WhatsAppService::class);
        $providerCredentials = (array) ($provider->credentials_encrypted ?? []);
        $statusCallbackUrl = rtrim((string) config('app.url'), '/').'/api/v1/webhooks/twilio/message-status';

        $result = $whatsAppService->send($validated['phone_number'], $payload, $statusCallbackUrl, $providerCredentials);

        // Task 3: Logging after sending
        \Illuminate\Support\Facades\Log::channel('single')->info('WhatsApp Debugger: After sending test message.', [
            'phone_number' => $validated['phone_number'],
            'template_name' => $template->template_name,
            'provider_account_id' => $provider->id,
            'result' => $result,
        ]);

        $normalizedTo = Str::startsWith($validated['phone_number'], '+') ? $validated['phone_number'] : '+'.$validated['phone_number'];
        $thread = MessageThread::query()->firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'channel' => 'whatsapp',
                'counterparty_number' => $normalizedTo,
            ],
            [
                'status' => 'open',
                'priority' => 'normal',
            ]
        );
        $thread->last_message_at = now();
        $thread->save();

        $providerMessageId = (string) ($result['provider_message_id'] ?? '');
        $status = (($result['ok'] ?? false) === true) ? 'accepted' : 'failed';
        $errorMessage = $result['error'] ?? null;
        $statusCode = $result['status_code'] ?? null;

        $message = Message::query()->create([
            'tenant_id' => $tenantId,
            'thread_id' => $thread->id,
            'direction' => 'outbound',
            'status' => $status,
            'body' => $bodyText,
            'sent_by_user_id' => $request->user()?->id,
            'provider_message_id' => $providerMessageId,
            'metadata' => [
                'channel' => 'whatsapp',
                'is_debug_test' => true,
                'template_key' => $template->template_name,
                'variables' => $validated['variables'] ?? [],
                'provider_account_id' => $provider->id,
                'debug_log' => [
                    'sent_payload' => $payload,
                    'response' => $result,
                ],
                'error' => $errorMessage,
                'status_code' => $statusCode,
            ],
            'sent_at' => now(),
        ]);

        $lead = \App\Models\Lead::query()
            ->where('tenant_id', $tenantId)
            ->where('phone', $normalizedTo)
            ->first();
        if ($lead) {
            \App\Models\LeadTimelineItem::query()->create([
                'tenant_id' => $tenantId,
                'lead_id' => $lead->id,
                'event_type' => 'message',
                'related_id' => $message->id,
                'related_type' => 'message',
                'title' => 'WhatsApp Debug Message Sent',
                'body' => $bodyText,
                'metadata' => [
                    'channel' => 'whatsapp',
                    'direction' => 'outbound',
                    'status' => $status,
                    'template_key' => $template->template_name,
                    'is_debug_test' => true,
                    'error' => $errorMessage,
                ],
                'occurred_at' => now(),
            ]);
        }

        return response()->json([
            'success' => $result['ok'] ?? false,
            'message' => $message,
            'debug_result' => $result,
        ]);
    }

    public function whatsappDebugDeliveryInspector(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-Id') ?? $request->user()->tenant_id;

        $messages = Message::query()
            ->where('tenant_id', $tenantId)
            ->where('direction', 'outbound')
            ->where(function($q) {
                $q->whereJsonContains('metadata->channel', 'whatsapp')
                  ->orWhereJsonContains('metadata->is_debug_test', true);
            })
            ->with('thread')
            ->latest()
            ->limit(100)
            ->get()
            ->map(function ($msg) {
                $errors = data_get($msg->metadata, 'meta_errors', []);
                $errCode = null;
                $errTitle = null;
                if (!empty($errors)) {
                    $errCode = data_get($errors[0], 'code');
                    $errTitle = data_get($errors[0], 'title');
                }

                return [
                    'id' => $msg->id,
                    'recipient' => (string) ($msg->thread?->counterparty_number ?? ''),
                    'body' => $msg->body,
                    'status' => $msg->status,
                    'created_at' => $msg->created_at?->toISOString(),
                    'delivered_at' => $msg->delivered_at?->toISOString(),
                    'read_at' => $msg->read_at?->toISOString(),
                    'error_code' => $errCode,
                    'error_title' => $errTitle,
                    'metadata' => $msg->metadata,
                ];
            });

        $allMessages = Message::query()
            ->where('tenant_id', $tenantId)
            ->where('direction', 'outbound')
            ->where(function($q) {
                $q->whereJsonContains('metadata->channel', 'whatsapp')
                  ->orWhereJsonContains('metadata->is_debug_test', true);
            })
            ->with('thread')
            ->get();

        $stats = $allMessages
            ->groupBy(function ($msg) {
                return (string) ($msg->thread?->counterparty_number ?? 'unknown');
            })
            ->map(function ($msgs, $number) {
                $total = $msgs->count();
                $delivered = $msgs->filter(fn($m) => in_array($m->status, ['delivered', 'read']))->count();
                $read = $msgs->filter(fn($m) => $m->status === 'read')->count();
                $failed = $msgs->filter(fn($m) => $m->status === 'failed' || $m->status === 'undelivered')->count();
                $held = $msgs->filter(fn($m) => $m->status === 'held_for_quality_assessment')->count();

                $errors = [];
                foreach ($msgs as $msg) {
                    $metaErrors = data_get($msg->metadata, 'meta_errors', []);
                    foreach ($metaErrors as $err) {
                        $code = data_get($err, 'code');
                        if ($code) {
                            $errors[$code] = ($errors[$code] ?? 0) + 1;
                        }
                    }
                }

                return [
                    'number' => $number,
                    'total_messages' => $total,
                    'delivered_count' => $delivered,
                    'read_count' => $read,
                    'failed_count' => $failed,
                    'held_count' => $held,
                    'delivery_rate' => $total > 0 ? round(($delivered / $total) * 100, 1) : 0,
                    'error_codes' => $errors,
                    'is_working' => $delivered > 0,
                ];
            })
            ->values();

        return response()->json([
            'data' => [
                'messages' => $messages,
                'comparison' => $stats,
            ]
        ]);
    }
}

