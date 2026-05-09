<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CampaignRun extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'campaign_id',
        'started_by',
        'paused_by',
        'status',
        'total_items',
        'queued_items',
        'completed_items',
        'failed_items',
        'retried_items',
        'calls_dispatched',
        'calls_connected',
        'calls_failed',
        'calls_per_minute',
        'calls_dispatched_in_window',
        'pacing_window_started_at',
        'started_at',
        'paused_at',
        'stopped_at',
        'last_tick_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'pacing_window_started_at' => 'datetime',
            'started_at' => 'datetime',
            'paused_at' => 'datetime',
            'stopped_at' => 'datetime',
            'last_tick_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function queueItems(): HasMany
    {
        return $this->hasMany(DialQueueItem::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(AgentAssignment::class);
    }
}
