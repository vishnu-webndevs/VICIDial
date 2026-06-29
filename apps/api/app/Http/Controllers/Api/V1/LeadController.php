<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DncEntry;
use App\Jobs\ProcessLeadImportJob;
use App\Models\Lead;
use App\Models\LeadImportJob;
use App\Models\LeadList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LeadController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:30'],
            'list_id' => ['nullable', 'uuid'],
            'exclude_dnc' => ['nullable', 'boolean'],
            'score_min' => ['nullable', 'integer', 'min:0'],
            'score_max' => ['nullable', 'integer', 'min:0'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 50);
        $query = Lead::query()->where('tenant_id', $tenant->id);
        if (! empty($validated['q'])) {
            $search = (string) $validated['q'];
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('full_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('company', 'like', "%{$search}%");
            });
        }
        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (! empty($validated['list_id'])) {
            $query->whereHas('lists', function ($builder) use ($validated): void {
                $builder->where('lead_lists.id', $validated['list_id']);
            });
        }
        if (($validated['exclude_dnc'] ?? true) === true) {
            $query->where('is_dnc', false);
        }
        if (array_key_exists('score_min', $validated)) {
            $query->where('engagement_score', '>=', (int) $validated['score_min']);
        }
        if (array_key_exists('score_max', $validated)) {
            $query->where('engagement_score', '<=', (int) $validated['score_max']);
        }

        $paginator = $query->with('lists:id')->latest('updated_at')->paginate($perPage);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'pagination' => [
                    'total' => $paginator->total(),
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                ],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'regex:/^\+[1-9]\d{7,14}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'in:new,contacted,qualified,proposal,won,lost,follow_up'],
            'owner_agent' => ['nullable', 'string', 'max:255'],
            'owner_agent_id' => ['nullable', 'uuid'],
            'next_follow_up_at' => ['nullable', 'date'],
            'engagement_score' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:100'],
            'notes' => ['nullable', 'array'],
            'notes.*' => ['string', 'max:500'],
            'list_ids' => ['nullable', 'array'],
            'list_ids.*' => ['uuid'],
        ]);

        $isDnc = DncEntry::query()
            ->where('tenant_id', $tenant->id)
            ->where('phone', $validated['phone'])
            ->exists();

        $lead = Lead::query()->create([
            ...$validated,
            'tenant_id' => $tenant->id,
            'owner_agent' => $validated['owner_agent'] ?? 'Unassigned',
            'owner_agent_id' => $validated['owner_agent_id'] ?? null,
            'engagement_score' => (int) ($validated['engagement_score'] ?? 0),
            'is_dnc' => $isDnc,
            'tags' => $validated['tags'] ?? [],
            'notes' => $validated['notes'] ?? [],
        ]);
        $this->syncListsForLead($tenant->id, $lead, (array) ($validated['list_ids'] ?? []));

        return response()->json(['data' => $lead], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'full_name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['sometimes', 'string', 'regex:/^\+[1-9]\d{7,14}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'in:new,contacted,qualified,proposal,won,lost,follow_up'],
            'owner_agent' => ['nullable', 'string', 'max:255'],
            'owner_agent_id' => ['nullable', 'uuid'],
            'next_follow_up_at' => ['nullable', 'date'],
            'engagement_score' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:100'],
            'notes' => ['nullable', 'array'],
            'notes.*' => ['string', 'max:500'],
            'list_ids' => ['nullable', 'array'],
            'list_ids.*' => ['uuid'],
        ]);

        $lead = Lead::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();
        if (array_key_exists('phone', $validated)) {
            $lead->is_dnc = DncEntry::query()
                ->where('tenant_id', $tenant->id)
                ->where('phone', $validated['phone'])
                ->exists();
        }
        $lead->fill($validated);
        $lead->save();
        if (array_key_exists('list_ids', $validated)) {
            $this->syncListsForLead($tenant->id, $lead, (array) $validated['list_ids']);
        }

        return response()->json(['data' => $lead]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $lead = Lead::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        $lead->delete();

        return response()->json(['success' => true]);
    }

    public function import(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
            'field_mapping' => ['nullable', 'array'],
            'field_mapping.full_name' => ['nullable', 'integer', 'min:0'],
            'field_mapping.phone' => ['nullable', 'integer', 'min:0'],
            'field_mapping.email' => ['nullable', 'integer', 'min:0'],
            'field_mapping.company' => ['nullable', 'integer', 'min:0'],
            'list_ids' => ['nullable', 'array'],
            'list_ids.*' => ['uuid'],
            'skip_duplicates' => ['nullable', 'boolean'],
            'skip_dnc' => ['nullable', 'boolean'],
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('file');
        $job = LeadImportJob::query()->create([
            'tenant_id' => $tenant->id,
            'created_by' => $request->user()?->id,
            'file_name' => $file->getClientOriginalName(),
            'source_path' => $this->storeUpload($tenant->id, $file),
            'field_mapping' => $request->input('field_mapping'),
            'target_list_ids' => $request->input('list_ids', []),
            'skip_duplicates' => (bool) $request->boolean('skip_duplicates', true),
            'skip_dnc' => (bool) $request->boolean('skip_dnc', true),
            'status' => 'queued',
        ]);

        ProcessLeadImportJob::dispatch($job->id);

        return response()->json([
            'data' => [
                'job_id' => $job->id,
                'status' => $job->status,
            ],
        ], 202);
    }

    public function importStatus(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $job = LeadImportJob::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        $progress = $job->total_rows > 0
            ? (int) floor(($job->processed_rows / max($job->total_rows, 1)) * 100)
            : ($job->status === 'completed' ? 100 : 0);

        return response()->json([
            'data' => [
                'id' => $job->id,
                'status' => $job->status,
                'file_name' => $job->file_name,
                'total_rows' => $job->total_rows,
                'processed_rows' => $job->processed_rows,
                'successful_rows' => $job->successful_rows,
                'failed_rows' => $job->failed_rows,
                'progress' => $progress,
                'errors' => $job->error_report ?? [],
                'started_at' => optional($job->started_at)->toISOString(),
                'finished_at' => optional($job->finished_at)->toISOString(),
                'created_at' => optional($job->created_at)->toISOString(),
            ],
        ]);
    }

    private function storeUpload(string $tenantId, UploadedFile $file): string
    {
        $dir = 'lead-imports/'.$tenantId;
        $name = Str::uuid()->toString().'-'.preg_replace('/[^a-zA-Z0-9\.\-_]/', '-', $file->getClientOriginalName());
        $path = $dir.'/'.$name;
        Storage::disk('local')->putFileAs($dir, $file, $name);

        return $path;
    }

    /**
     * @param  array<int, string>  $listIds
     */
    private function syncListsForLead(string $tenantId, Lead $lead, array $listIds): void
    {
        if ($listIds === []) {
            return;
        }

        $validListIds = LeadList::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $listIds)
            ->pluck('id')
            ->values()
            ->all();

        $pivot = [];
        foreach ($validListIds as $listId) {
            $pivot[$listId] = [
                'tenant_id' => $tenantId,
                'attached_at' => now(),
            ];
        }

        $lead->lists()->sync($pivot);
    }
}
