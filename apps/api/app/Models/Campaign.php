<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'created_by',
        'preferred_provider_account_id',
        'name',
        'type',
        'status',
        'lead_list_name',
        'schedule_window',
        'retry_limit',
        'queue_size',
        'calls_per_minute',
        'auto_pause_when_no_agents',
        'priority',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'retry_limit' => 'integer',
            'queue_size' => 'integer',
            'calls_per_minute' => 'integer',
            'auto_pause_when_no_agents' => 'boolean',
            'priority' => 'integer',
            'settings' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function preferredProviderAccount(): BelongsTo
    {
        return $this->belongsTo(ProviderAccount::class, 'preferred_provider_account_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(CampaignRun::class);
    }

    public function queueItems(): HasMany
    {
        return $this->hasMany(DialQueueItem::class);
    }

    public function agentAssignments(): HasMany
    {
        return $this->hasMany(CampaignAgentAssignment::class);
    }

    public function isWithinScheduleWindow(): bool
    {
        $window = trim((string) $this->schedule_window);
        if ($window === '') {
            return true;
        }

        // Fetch timezone
        $timezone = (string) TenantSetting::query()
            ->where('tenant_id', $this->tenant_id)
            ->value('timezone') ?: 'UTC';

        try {
            $now = \Illuminate\Support\Carbon::now($timezone);
        } catch (\Throwable) {
            $now = \Illuminate\Support\Carbon::now('UTC');
        }

        // Format is typically: [Days] [HH:mm]-[HH:mm] e.g. "Mon-Fri 09:00-18:00"
        if (preg_match('/^(?:([A-Za-z, -]+)\s+)?(\d{2}:\d{2})-(\d{2}:\d{2})$/', $window, $matches)) {
            $daysPart = isset($matches[1]) ? trim($matches[1]) : '';
            $startTime = $matches[2];
            $endTime = $matches[3];

            // 1. Check days part if present
            if ($daysPart !== '') {
                $currentDayName = $now->format('D'); // Mon, Tue, Wed, etc.
                $allowedDays = $this->parseDaysRange($daysPart);
                if (!in_array($currentDayName, $allowedDays, true)) {
                    return false;
                }
            }

            // 2. Check time part
            $currentTime = $now->format('H:i');
            if ($startTime <= $endTime) {
                return $currentTime >= $startTime && $currentTime <= $endTime;
            } else {
                // Cross-midnight window
                return $currentTime >= $startTime || $currentTime <= $endTime;
            }
        }

        return true;
    }

    public function isWithinGlobalTenantCallingWindow(): bool
    {
        $tenantSetting = TenantSetting::query()
            ->where('tenant_id', $this->tenant_id)
            ->first();

        if ($tenantSetting) {
            $metadata = (array) ($tenantSetting->metadata ?? []);
            $callingWindow = (array) ($metadata['calling_window'] ?? []);
            if ($callingWindow !== []) {
                $days = (array) ($callingWindow['days'] ?? []);
                $start = (string) ($callingWindow['start_time'] ?? '');
                $end = (string) ($callingWindow['end_time'] ?? '');
                $timezone = (string) ($callingWindow['timezone'] ?? '') ?: $tenantSetting->timezone ?: 'UTC';

                try {
                    $now = \Illuminate\Support\Carbon::now($timezone);
                } catch (\Throwable) {
                    $now = \Illuminate\Support\Carbon::now('UTC');
                }

                // Check days
                if ($days !== []) {
                    $currentDay = $now->format('D'); // Mon, Tue, etc.
                    if (!in_array($currentDay, $days, true)) {
                        return false;
                    }
                }

                // Check time
                if ($start !== '' && $end !== '') {
                    $currentTime = $now->format('H:i');
                    if ($start <= $end) {
                        if ($currentTime < $start || $currentTime > $end) {
                            return false;
                        }
                    } else {
                        // Cross-midnight window
                        if ($currentTime < $start && $currentTime > $end) {
                            return false;
                        }
                    }
                }
            }
        }

        return true;
    }

    private function parseDaysRange(string $daysPart): array
    {
        $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        $daysMap = array_flip($days);

        $allowedDays = [];
        $parts = explode(',', $daysPart);

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            if (str_contains($part, '-')) {
                $rangeParts = explode('-', $part);
                $startDay = trim($rangeParts[0] ?? '');
                $endDay = trim($rangeParts[1] ?? '');

                if (isset($daysMap[$startDay]) && isset($daysMap[$endDay])) {
                    $startIndex = $daysMap[$startDay];
                    $endIndex = $daysMap[$endDay];

                    if ($startIndex <= $endIndex) {
                        $rangeDays = array_slice($days, $startIndex, $endIndex - $startIndex + 1);
                    } else {
                        // Wrap around
                        $rangeDays = array_merge(
                            array_slice($days, $startIndex),
                            array_slice($days, 0, $endIndex + 1)
                        );
                    }
                    $allowedDays = array_merge($allowedDays, $rangeDays);
                }
            } else {
                if (in_array($part, $days, true)) {
                    $allowedDays[] = $part;
                }
            }
        }

        return array_unique($allowedDays);
    }
}

