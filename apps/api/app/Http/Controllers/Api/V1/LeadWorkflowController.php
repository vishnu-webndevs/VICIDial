<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CallSession;
use App\Models\DncEntry;
use App\Models\Lead;
use App\Models\LeadDisposition;
use App\Models\LeadList;
use App\Models\LeadTimelineItem;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

class LeadWorkflowController extends Controller
{
    public function listsIndex(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $lists = LeadList::query()
            ->withCount('leads')
            ->where('tenant_id', $tenant->id)
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $lists]);
    }

    public function listsStore(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $list = LeadList::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ]);

        return response()->json(['data' => $list], 201);
    }

    public function listsAttachLeads(Request $request, string $listId): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $list = LeadList::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $listId)
            ->firstOrFail();
        $validated = $request->validate([
            'lead_ids' => ['required', 'array', 'min:1'],
            'lead_ids.*' => ['uuid'],
        ]);

        $leadIds = Lead::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('id', (array) $validated['lead_ids'])
            ->pluck('id')
            ->values();

        $pivot = [];
        foreach ($leadIds as $leadId) {
            $pivot[$leadId] = ['tenant_id' => $tenant->id, 'attached_at' => now()];
        }
        $list->leads()->syncWithoutDetaching($pivot);

        return response()->json([
            'data' => [
                'list_id' => $list->id,
                'attached_count' => count($pivot),
            ],
        ]);
    }

    public function dncIndex(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $entries = DncEntry::query()
            ->where('tenant_id', $tenant->id)
            ->latest('created_at')
            ->paginate((int) $request->integer('per_page', 50));

        return response()->json([
            'data' => $entries->items(),
            'meta' => [
                'pagination' => [
                    'total' => $entries->total(),
                    'per_page' => $entries->perPage(),
                    'current_page' => $entries->currentPage(),
                    'last_page' => $entries->lastPage(),
                ],
            ],
        ]);
    }

    public function dncStore(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'phone' => ['required', 'string', 'regex:/^\+[1-9]\d{7,14}$/'],
            'source' => ['nullable', 'string', 'max:50'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $entry = DncEntry::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'phone' => $validated['phone']],
            [
                'source' => $validated['source'] ?? 'manual',
                'reason' => $validated['reason'] ?? null,
                'created_by' => $request->user()?->id,
                'effective_at' => now(),
            ]
        );

        Lead::query()
            ->where('tenant_id', $tenant->id)
            ->where('phone', $validated['phone'])
            ->update(['is_dnc' => true, 'status' => 'dnc']);

        return response()->json(['data' => $entry], 201);
    }

    public function dncDestroy(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $entry = DncEntry::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();
        $phone = $entry->phone;
        $entry->delete();

        Lead::query()
            ->where('tenant_id', $tenant->id)
            ->where('phone', $phone)
            ->update(['is_dnc' => false]);

        return response()->json([], 204);
    }

    public function dispositionStore(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'lead_id' => ['required', 'uuid'],
            'call_session_id' => ['nullable', 'uuid'],
            'disposition' => ['required', 'in:interested,callback,voicemail,dnc,not_interested,no_answer,busy,wrong_number,converted'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'callback_at' => ['nullable', 'date'],
        ]);

        $lead = Lead::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $validated['lead_id'])
            ->firstOrFail();

        $disposition = LeadDisposition::query()->create([
            'tenant_id' => $tenant->id,
            'lead_id' => $lead->id,
            'call_session_id' => $validated['call_session_id'] ?? null,
            'agent_id' => $request->user()?->id,
            'disposition' => $validated['disposition'],
            'notes' => $validated['notes'] ?? null,
            'callback_at' => $validated['callback_at'] ?? null,
            'auto_rescheduled' => $validated['disposition'] === 'callback' && ! empty($validated['callback_at']),
            'metadata' => [],
        ]);

        $lead->last_disposition = [
            'type' => $validated['disposition'],
            'notes' => $validated['notes'] ?? null,
            'at' => now()->toISOString(),
        ];
        $lead->call_attempts = (int) $lead->call_attempts + 1;
        $lead->last_contacted_at = now();
        if ($validated['disposition'] === 'dnc') {
            DncEntry::query()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'phone' => $lead->phone],
                [
                    'source' => 'disposition',
                    'reason' => $validated['notes'] ?? 'Set from call disposition.',
                    'created_by' => $request->user()?->id,
                    'effective_at' => now(),
                ]
            );
            $lead->is_dnc = true;
            $lead->status = 'dnc';
        } elseif ($validated['disposition'] === 'callback' && ! empty($validated['callback_at'])) {
            $lead->status = 'follow_up';
            $lead->next_follow_up_at = $validated['callback_at'];
        } elseif ($validated['disposition'] === 'interested') {
            $lead->status = 'qualified';
        } elseif ($validated['disposition'] === 'converted') {
            $lead->status = 'won';
        }
        $lead->engagement_score = $this->scoreForDisposition($validated['disposition'], (int) $lead->engagement_score);
        $lead->save();

        LeadTimelineItem::query()->create([
            'tenant_id' => $tenant->id,
            'lead_id' => $lead->id,
            'event_type' => 'disposition',
            'related_id' => $disposition->id,
            'related_type' => 'lead_disposition',
            'actor_id' => $request->user()?->id,
            'content' => $validated['notes'] ?? null,
            'metadata' => [
                'disposition' => $validated['disposition'],
                'callback_at' => $validated['callback_at'] ?? null,
            ],
            'occurred_at' => now(),
        ]);

        return response()->json(['data' => $disposition], 201);
    }

    public function timeline(Request $request, string $leadId): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $lead = Lead::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $leadId)
            ->firstOrFail();

        $phone = $lead->phone;
        $calls = CallSession::query()
            ->with('initiatedByUser')
            ->where('tenant_id', $tenant->id)
            ->where(function ($query) use ($phone) {
                $query->where('to_number', $phone)->orWhere('from_number', $phone);
            })
            ->latest('created_at')
            ->limit(100)
            ->get()
            ->map(fn (CallSession $call) => [
                'type' => 'call',
                'id' => $call->id,
                'direction' => $call->direction,
                'duration' => $call->duration_seconds,
                'disposition' => $call->status,
                'agent' => $call->initiatedByUser
                    ? trim(((string) $call->initiatedByUser->first_name).' '.((string) $call->initiatedByUser->last_name))
                    : null,
                'recording_url' => $call->recording_url,
                'recording_duration' => $call->recording_duration,
                'at' => $call->created_at?->toISOString(),
            ]);

        $messages = Message::query()
            ->select('messages.*', 'message_threads.channel as thread_channel')
            ->join('message_threads', 'message_threads.id', '=', 'messages.thread_id')
            ->where('messages.tenant_id', $tenant->id)
            ->where('message_threads.counterparty_number', $phone)
            ->latest('messages.created_at')
            ->limit(100)
            ->get();
        $messageUsers = User::query()
            ->whereIn('id', $messages->pluck('sent_by_user_id')->filter()->values())
            ->get()
            ->keyBy('id');
        $messageTimeline = $messages->map(function (Message $message) use ($messageUsers) {
            $channel = (string) (($message->metadata['channel'] ?? null) ?: $message->getAttribute('thread_channel') ?: 'sms');
            $sender = $message->sent_by_user_id ? $messageUsers->get($message->sent_by_user_id) : null;

            return [
                'type' => $channel,
                'id' => $message->id,
                'direction' => $message->direction,
                'content' => $message->body,
                'agent' => $sender ? trim(((string) $sender->first_name).' '.((string) $sender->last_name)) : null,
                'at' => $message->created_at?->toISOString(),
            ];
        });

        $dispositions = LeadDisposition::query()
            ->where('tenant_id', $tenant->id)
            ->where('lead_id', $lead->id)
            ->latest('created_at')
            ->limit(100)
            ->get()
            ->map(fn (LeadDisposition $item) => [
                'type' => $item->disposition === 'callback' ? 'callback' : 'note',
                'id' => $item->id,
                'content' => $item->notes,
                'status' => (string) (($item->metadata['callback_status'] ?? null) ?: 'scheduled'),
                'scheduled_at' => $item->callback_at?->toISOString(),
                'at' => $item->created_at?->toISOString(),
            ]);

        $events = LeadTimelineItem::query()
            ->where('tenant_id', $tenant->id)
            ->where('lead_id', $lead->id)
            ->whereNotIn('event_type', ['sms', 'whatsapp', 'disposition'])
            ->latest('occurred_at')
            ->limit(100)
            ->get()
            ->map(fn (LeadTimelineItem $item) => [
                'type' => in_array($item->event_type, ['callback', 'callback.updated'], true) ? 'callback' : 'note',
                'id' => $item->id,
                'content' => $item->content,
                'status' => (string) (($item->metadata['callback_status'] ?? null) ?: 'pending'),
                'scheduled_at' => isset($item->metadata['callback_at']) ? (string) $item->metadata['callback_at'] : null,
                'at' => $item->occurred_at?->toISOString(),
            ]);

        $timeline = collect()
            ->concat($calls)
            ->concat($messageTimeline)
            ->concat($dispositions)
            ->concat($events)
            ->sortByDesc('at')
            ->values();

        $perPage = max(1, min(100, (int) $request->integer('per_page', 25)));
        $page = max(1, (int) $request->integer('page', 1));
        $paginator = new LengthAwarePaginator(
            $timeline->forPage($page, $perPage)->values()->all(),
            $timeline->count(),
            $perPage,
            $page
        );

        return response()->json([
            'data' => [
                'lead_id' => $lead->id,
                'lead' => $lead,
                'timeline' => $paginator->items(),
            ],
            'meta' => [
                'pagination' => [
                    'total' => $paginator->total(),
                    'per_page' => $paginator->perPage(),
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                ],
            ],
        ]);
    }

    public function callbacks(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $query = LeadDisposition::query()
            ->with('lead')
            ->where('tenant_id', $tenant->id)
            ->where('disposition', 'callback')
            ->whereNotNull('callback_at')
            ->when(
                $request->input('state') === 'due',
                fn ($q) => $q->where('callback_at', '<=', now()),
                fn ($q) => $q->where('callback_at', '>=', now()->subDays(30))
            )
            ->orderBy('callback_at');

        $items = $query->paginate((int) $request->integer('per_page', 50));

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

    public function callbackUpdate(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'callback_at' => ['nullable', 'date'],
            'status' => ['nullable', 'in:scheduled,completed,canceled'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $item = LeadDisposition::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->where('disposition', 'callback')
            ->firstOrFail();

        if (array_key_exists('callback_at', $validated)) {
            $item->callback_at = $validated['callback_at'];
        }
        $metadata = (array) ($item->metadata ?? []);
        if (! empty($validated['status'])) {
            $metadata['callback_status'] = $validated['status'];
            $metadata['callback_status_updated_at'] = now()->toISOString();
        }
        if (! empty($validated['notes'])) {
            $metadata['callback_notes'] = $validated['notes'];
        }
        $item->metadata = $metadata;
        $item->save();

        LeadTimelineItem::query()->create([
            'tenant_id' => $tenant->id,
            'lead_id' => $item->lead_id,
            'event_type' => 'callback.updated',
            'related_id' => $item->id,
            'related_type' => 'lead_disposition',
            'actor_id' => $request->user()?->id,
            'content' => $validated['notes'] ?? null,
            'metadata' => $metadata,
            'occurred_at' => now(),
        ]);

        return response()->json(['data' => $item->fresh('lead')]);
    }

    private function scoreForDisposition(string $disposition, int $currentScore): int
    {
        return match ($disposition) {
            'interested' => min(1000, $currentScore + 20),
            'converted' => min(1000, $currentScore + 40),
            'callback' => min(1000, $currentScore + 10),
            'not_interested' => max(0, $currentScore - 15),
            'wrong_number' => max(0, $currentScore - 25),
            default => $currentScore,
        };
    }
}
