<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentPhoneAssignment extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'agent_id',
        'provider_phone_number_id',
        'status',
        'assigned_by',
        'assigned_at',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
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

    public function number(): BelongsTo
    {
        return $this->belongsTo(ProviderPhoneNumber::class, 'provider_phone_number_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
