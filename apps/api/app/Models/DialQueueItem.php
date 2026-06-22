<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property int $attempt_count
 */
class DialQueueItem extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'campaign_id',
        'campaign_run_id',
        'lead_id',
        'assigned_agent_entity_id',
        'last_call_session_id',
        'priority',
        'attempt_count',
        'max_attempts',
        'status',
        'failure_reason',
        'available_at',
        'enqueued_at',
        'processed_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'available_at' => 'datetime',
            'enqueued_at' => 'datetime',
            'processed_at' => 'datetime',
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

    public function run(): BelongsTo
    {
        return $this->belongsTo(CampaignRun::class, 'campaign_run_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function assignedAgentEntity(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'assigned_agent_entity_id');
    }

    public function lastCallSession(): BelongsTo
    {
        return $this->belongsTo(CallSession::class, 'last_call_session_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(AgentAssignment::class);
    }
}
