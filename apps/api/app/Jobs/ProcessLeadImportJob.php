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
        $fieldMap = [];
        $listIds = $this->resolveValidListIds($job);
        $seenPhones = [];

        try {
            $absolutePath = Storage::disk('local')->path($job->source_path);
            $extension = strtolower(pathinfo($job->file_name ?? $job->source_path, PATHINFO_EXTENSION));

            $rows = [];
            if (in_array($extension, ['xlsx', 'xls', 'ods'], true) && class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
                $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($absolutePath);
                $worksheet = $spreadsheet->getActiveSheet();
                $rows = $worksheet->toArray(null, true, true, false);
            } else {
                $file = new \SplFileObject($absolutePath);
                $file->setFlags(\SplFileObject::READ_CSV | \SplFileObject::SKIP_EMPTY);
                foreach ($file as $line) {
                    if (is_array($line) && count($line) > 0) {
                        $rows[] = $line;
                    }
                }
            }

            $firstLine = $rows[0] ?? null;
            $fieldMap = $this->resolveFieldMapping($job->field_mapping, $firstLine, $rows);

            for ($i = 0; $i < count($rows); $i++) {
                $row = $rows[$i];
                if (! is_array($row) || count($row) === 0) {
                    continue;
                }

                $rowText = strtolower(implode(' ', array_map('strval', $row)));
                if (
                    str_contains($rowText, 's. no.') ||
                    str_contains($rowText, 's.no') ||
                    (str_contains($rowText, 'phone number') && str_contains($rowText, 'name')) ||
                    (str_contains($rowText, 'mobile') && str_contains($rowText, 'name'))
                ) {
                    // Embedded header row in multi-table sheets, skip silently
                    continue;
                }

                $fullName = trim((string) ($row[$fieldMap['full_name']] ?? ''));
                $rawPhone = trim((string) ($row[$fieldMap['phone']] ?? ''));
                $email = trim((string) ($row[$fieldMap['email']] ?? ''));
                $company = trim((string) ($row[$fieldMap['company']] ?? ''));

                // Dynamic row fallback: If mapped phone cell is not a phone number, scan all row cells for 10-digit mobile number
                [$testPhone] = $this->sanitizeAndValidatePhone($rawPhone);
                if ($testPhone === null) {
                    foreach ($row as $cell) {
                        $cellVal = trim((string) $cell);
                        [$candidatePhone] = $this->sanitizeAndValidatePhone($cellVal);
                        if ($candidatePhone !== null) {
                            $rawPhone = $cellVal;
                            break;
                        }
                    }
                }

                if ($rawPhone === '') {
                    continue;
                }

                ++$totalRows;

                [$sanitizedPhone, $phoneError] = $this->sanitizeAndValidatePhone($rawPhone);
                if ($phoneError !== null || $sanitizedPhone === null) {
                    ++$failedRows;
                    if (count($errors) < 500) {
                        $errors[] = [
                            'row' => $totalRows,
                            'name' => $this->isValidLeadName($fullName) ? $fullName : 'Un-named Lead',
                            'phone' => $rawPhone,
                            'message' => $phoneError ?? 'Invalid phone number.',
                        ];
                    }
                    continue;
                }

                $phone = $sanitizedPhone;
                $digitsOnly = preg_replace('/\D+/', '', $rawPhone);

                // Locate clean contact name in row, avoiding Sr. No., dates, and cellVal
                if (! $this->isValidLeadName($fullName)) {
                    $fullName = '';
                    foreach ($row as $nameCell) {
                        $nVal = trim((string) $nameCell);
                        $nDigits = preg_replace('/\D+/', '', $nVal);
                        if ($nDigits === $digitsOnly) {
                            continue;
                        }
                        if ($this->isValidLeadName($nVal)) {
                            $fullName = $nVal;
                            break;
                        }
                    }
                    if ($fullName === '') {
                        $fullName = 'Lead '.substr($digitsOnly, -4);
                    }
                }

                if ($job->skip_dnc && DncEntry::query()->where('tenant_id', $job->tenant_id)->where('phone', $phone)->exists()) {
                    ++$failedRows;
                    if (count($errors) < 500) {
                        $errors[] = [
                            'row' => $totalRows,
                            'name' => $fullName,
                            'phone' => $rawPhone,
                            'message' => 'Phone number exists in Do-Not-Call (DNC) list.',
                        ];
                    }
                    continue;
                }

                if ($job->skip_duplicates) {
                    if (isset($seenPhones[$phone]) || Lead::query()->where('tenant_id', $job->tenant_id)->where('phone', $phone)->exists()) {
                        ++$failedRows;
                        if (count($errors) < 500) {
                            $errors[] = [
                                'row' => $totalRows,
                                'name' => $fullName,
                                'phone' => $rawPhone,
                                'message' => 'Duplicate lead phone number already exists.',
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
     * Dynamically resolve column index mapping for full_name, phone, email, company.
     *
     * @param  array<string, mixed>|null  $userMapping
     * @param  array<int, mixed>|null  $firstLine
     * @param  array<int, array<int, mixed>>  $rows
     * @return array<string, int>
     */
    private function resolveFieldMapping(?array $userMapping, ?array $firstLine, array $rows): array
    {
        if (! empty($userMapping) && (isset($userMapping['full_name']) || isset($userMapping['phone']))) {
            return [
                'full_name' => max(0, (int) ($userMapping['full_name'] ?? 0)),
                'phone' => max(0, (int) ($userMapping['phone'] ?? 1)),
                'email' => max(0, (int) ($userMapping['email'] ?? 2)),
                'company' => max(0, (int) ($userMapping['company'] ?? 3)),
            ];
        }

        $map = ['full_name' => -1, 'phone' => -1, 'email' => -1, 'company' => -1];

        if (is_array($firstLine)) {
            foreach ($firstLine as $index => $colName) {
                $colStr = strtolower(trim((string) $colName));
                if ($colStr === '') {
                    continue;
                }

                if ($map['phone'] === -1 && (
                    str_contains($colStr, 'phone') || str_contains($colStr, 'mobile') || str_contains($colStr, 'contact') || str_contains($colStr, 'number') || str_contains($colStr, 'cell') || str_contains($colStr, 'call')
                )) {
                    $map['phone'] = (int) $index;
                } elseif ($map['full_name'] === -1 && (
                    str_contains($colStr, 'name') || str_contains($colStr, 'customer') || str_contains($colStr, 'client') || str_contains($colStr, 'detail') || str_contains($colStr, 'person')
                )) {
                    $map['full_name'] = (int) $index;
                } elseif ($map['email'] === -1 && str_contains($colStr, 'email')) {
                    $map['email'] = (int) $index;
                } elseif ($map['company'] === -1 && (
                    str_contains($colStr, 'company') || str_contains($colStr, 'business') || str_contains($colStr, 'org')
                )) {
                    $map['company'] = (int) $index;
                }
            }
        }

        if ($map['phone'] === -1 || $map['full_name'] === -1) {
            $sampleRows = array_slice($rows, 1, 10);
            foreach ($sampleRows as $sampleRow) {
                if (! is_array($sampleRow)) {
                    continue;
                }
                foreach ($sampleRow as $idx => $cellVal) {
                    $digits = preg_replace('/\D+/', '', (string) $cellVal);
                    if ($map['phone'] === -1 && strlen($digits) >= 10 && strlen($digits) <= 12) {
                        $map['phone'] = (int) $idx;
                    } elseif ($map['full_name'] === -1 && strlen((string) $cellVal) > 1 && ! is_numeric($cellVal) && strlen($digits) < 8) {
                        $map['full_name'] = (int) $idx;
                    }
                }
            }
        }

        return [
            'phone' => $map['phone'] !== -1 ? $map['phone'] : 1,
            'full_name' => $map['full_name'] !== -1 ? $map['full_name'] : 0,
            'email' => $map['email'] !== -1 ? $map['email'] : 2,
            'company' => $map['company'] !== -1 ? $map['company'] : 3,
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

    /**
     * Sanitize and validate phone numbers for 10-digit mobile / E.164 compliance.
     * Returns array [formattedPhone|null, errorMessage|null]
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function sanitizeAndValidatePhone(string $rawPhone): array
    {
        $rawPhone = trim($rawPhone);
        if ($rawPhone === '') {
            return [null, 'Phone number is empty.'];
        }

        $hasPlus = str_starts_with($rawPhone, '+');
        $digitsOnly = preg_replace('/\D+/', '', $rawPhone);

        if ($digitsOnly === '') {
            return [null, "No valid digits found in '{$rawPhone}'."];
        }

        if ($hasPlus) {
            $e164Candidate = '+'.$digitsOnly;
            if (preg_match('/^\+[1-9]\d{7,14}$/', $e164Candidate)) {
                return [$e164Candidate, null];
            }

            return [null, "Invalid E.164 phone format '{$rawPhone}'."];
        }

        if (strlen($digitsOnly) === 11 && str_starts_with($digitsOnly, '0')) {
            $digitsOnly = substr($digitsOnly, 1);
        }

        if (strlen($digitsOnly) === 12 && str_starts_with($digitsOnly, '91')) {
            $digitsOnly = substr($digitsOnly, 2);
        }

        if (strlen($digitsOnly) === 10) {
            return ['+91'.$digitsOnly, null];
        }

        $digitCount = strlen($digitsOnly);

        return [null, "Invalid phone number length ({$digitCount} digits: '{$rawPhone}'). Must be a 10-digit mobile number."];
    }

    /**
     * Check if a string is a valid contact name (excluding Sr. No., headers, dates).
     */
    private function isValidLeadName(string $val): bool
    {
        $v = trim($val);
        if ($v === '' || is_numeric($v)) {
            return false;
        }

        $lower = strtolower($v);
        if (in_array($lower, ['s. no.', 's.no', 'sr. no.', 'sr.no', 'sn', 'sno', 'sl. no.', 'sl.no', 'phone number', 'phone', 'mobile', 'name', 'remarks / additional notes', '(name faded/unclear)'], true)) {
            return false;
        }

        if (preg_match('/^\d{1,2}\s+[a-z]{3}$/i', $v) || preg_match('/^\d{1,2}[\/\.-]\d{1,2}([\/\.-]\d{2,4})?$/', $v)) {
            return false;
        }

        return true;
    }
}
