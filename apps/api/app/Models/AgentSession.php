<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentSession extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'agent_id',
        'status',
        'capacity',
        'active_assignments',
        'last_heartbeat_at',
        'available_since',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'last_heartbeat_at' => 'datetime',
            'available_since' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(AgentAssignment::class);
    }
}
