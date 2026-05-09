<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Agent extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'company_number',
        'status',
        'metadata',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
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

    public function sessions(): HasMany
    {
        return $this->hasMany(AgentSession::class);
    }

    public function phoneAssignments(): HasMany
    {
        return $this->hasMany(AgentPhoneAssignment::class);
    }

    public function campaignAssignments(): HasMany
    {
        return $this->hasMany(CampaignAgentAssignment::class);
    }
}
