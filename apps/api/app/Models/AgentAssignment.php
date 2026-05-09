<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentAssignment extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'campaign_id',
        'campaign_run_id',
        'dial_queue_item_id',
        'agent_id',
        'agent_session_id',
        'status',
        'assigned_at',
        'released_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'assigned_at' => 'datetime',
            'released_at' => 'datetime',
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

    public function queueItem(): BelongsTo
    {
        return $this->belongsTo(DialQueueItem::class, 'dial_queue_item_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function agentSession(): BelongsTo
    {
        return $this->belongsTo(AgentSession::class);
    }
}
