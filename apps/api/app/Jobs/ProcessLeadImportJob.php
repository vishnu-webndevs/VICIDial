<?php

namespace App\Jobs;

use App\Models\DncEntry;
use App\Models\Lead;
use App\Models\LeadImportJob;
use App\Models\LeadList;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProcessLeadImportJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $leadImportJobId)
    {
    }

    public function handle(): void
    {
        $job = LeadImportJob::query()->findOrFail($this->leadImportJobId);
        if (! in_array($job->status, ['queued', 'processing'], true)) {
            return;
        }

        $job->status = 'processing';
        $job->started_at = $job->started_at ?? now();
        $job->save();

        $errors = [];
        $totalRows = 0;
        $processedRows = 0;
        $successRows = 0;
        $failedRows = 0;
        $fieldMap = $this->normalizeFieldMap((array) ($job->field_mapping ?? []));
        $listIds = $this->resolveValidListIds($job);
        $seenPhones = [];

        try {
            if (! Storage::disk('local')->exists($job->source_path)) {
                throw new \RuntimeException('Uploaded CSV source file is no longer available.');
            }

            $absolutePath = Storage::disk('local')->path($job->source_path);
            $file = new \SplFileObject($absolutePath);
            $file->setFlags(\SplFileObject::READ_CSV | \SplFileObject::SKIP_EMPTY);

            $firstLine = $file->fgetcsv();
            $hasHeader = is_array($firstLine)
                && isset($firstLine[0])
                && is_string($firstLine[0])
                && in_array(strtolower(trim($firstLine[0])), ['full_name', 'name'], true);
            if (! $hasHeader) {
                $file->rewind();
            }

            foreach ($file as $row) {
                if (! is_array($row) || count($row) === 0) {
                    continue;
                }

                $fullName = trim((string) ($row[$fieldMap['full_name']] ?? ''));
                $phone = trim((string) ($row[$fieldMap['phone']] ?? ''));
                $email = trim((string) ($row[$fieldMap['email']] ?? ''));
                $company = trim((string) ($row[$fieldMap['company']] ?? ''));

                // Guard against CSV header rows that can still appear in iteration.
                if (
                    in_array(strtolower($fullName), ['full_name', 'name'], true)
                    && strtolower($phone) === 'phone'
                ) {
                    continue;
                }

                ++$totalRows;

                if ($fullName === '' || $phone === '') {
                    ++$failedRows;
                    if (count($errors) < 200) {
                        $errors[] = [
                            'row' => $totalRows,
                            'message' => 'full_name and phone are required.',
                        ];
                    }
                    continue;
                }

                if (! preg_match('/^\+[1-9]\d{7,14}$/', $phone)) {
                    ++$failedRows;
                    if (count($errors) < 200) {
                        $errors[] = [
                            'row' => $totalRows,
                            'message' => 'phone must be in E.164 format.',
                        ];
                    }
                    continue;
                }

                if ($job->skip_dnc && DncEntry::query()->where('tenant_id', $job->tenant_id)->where('phone', $phone)->exists()) {
                    ++$failedRows;
                    if (count($errors) < 200) {
                        $errors[] = [
                            'row' => $totalRows,
                            'message' => 'Phone exists in DNC list.',
                        ];
                    }
                    continue;
                }

                if ($job->skip_duplicates) {
                    if (isset($seenPhones[$phone]) || Lead::query()->where('tenant_id', $job->tenant_id)->where('phone', $phone)->exists()) {
                        ++$failedRows;
                        if (count($errors) < 200) {
                            $errors[] = [
                                'row' => $totalRows,
                                'message' => 'Duplicate lead phone detected.',
                            ];
                        }
                        continue;
                    }
                    $seenPhones[$phone] = true;
                }

                $lead = Lead::query()->create([
                    'id' => (string) Str::uuid(),
                    'tenant_id' => $job->tenant_id,
                    'import_job_id' => $job->id,
                    'full_name' => $fullName,
                    'phone' => $phone,
                    'email' => $email !== '' ? $email : null,
                    'company' => $company !== '' ? $company : null,
                    'status' => 'new',
                    'owner_agent' => 'Unassigned',
                    'engagement_score' => 0,
                    'is_dnc' => false,
                    'tags' => [],
                    'notes' => ['Imported from CSV upload'],
                ]);
                if ($listIds !== []) {
                    $pivot = [];
                    foreach ($listIds as $listId) {
                        $pivot[$listId] = ['tenant_id' => $job->tenant_id, 'attached_at' => now()];
                    }
                    $lead->lists()->syncWithoutDetaching($pivot);
                }
                ++$processedRows;
                ++$successRows;

                if ($processedRows % 100 === 0) {
                    $job->update([
                        'total_rows' => $totalRows,
                        'processed_rows' => $processedRows + $failedRows,
                        'successful_rows' => $successRows,
                        'failed_rows' => $failedRows,
                        'error_report' => $errors,
                    ]);
                }
            }

            $job->update([
                'status' => 'completed',
                'total_rows' => $totalRows,
                'processed_rows' => $successRows + $failedRows,
                'successful_rows' => $successRows,
                'failed_rows' => $failedRows,
                'error_report' => $errors,
                'finished_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            $job->update([
                'status' => 'failed',
                'total_rows' => $totalRows,
                'processed_rows' => $successRows + $failedRows,
                'successful_rows' => $successRows,
                'failed_rows' => $failedRows + 1,
                'error_report' => array_slice(
                    array_merge($errors, [['row' => null, 'message' => $exception->getMessage()]]),
                    0,
                    200
                ),
                'finished_at' => now(),
            ]);

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $mapping
     * @return array<string, int>
     */
    private function normalizeFieldMap(array $mapping): array
    {
        return [
            'full_name' => max(0, (int) ($mapping['full_name'] ?? 0)),
            'phone' => max(0, (int) ($mapping['phone'] ?? 1)),
            'email' => max(0, (int) ($mapping['email'] ?? 2)),
            'company' => max(0, (int) ($mapping['company'] ?? 3)),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function resolveValidListIds(LeadImportJob $job): array
    {
        $targetListIds = collect((array) ($job->target_list_ids ?? []))
            ->filter(fn ($id) => is_string($id) && $id !== '')
            ->values()
            ->all();

        if ($targetListIds === []) {
            return [];
        }

        return LeadList::query()
            ->where('tenant_id', $job->tenant_id)
            ->whereIn('id', $targetListIds)
            ->pluck('id')
            ->values()
            ->all();
    }
}
