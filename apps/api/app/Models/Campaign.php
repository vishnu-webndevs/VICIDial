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
}
