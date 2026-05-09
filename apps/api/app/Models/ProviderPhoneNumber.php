<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProviderPhoneNumber extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'provider_account_id',
        'provider_number_sid',
        'phone_number',
        'friendly_name',
        'status',
        'is_validated',
        'last_tested_at',
        'capabilities',
        'last_error_code',
        'last_error_message',
    ];

    protected function casts(): array
    {
        return [
            'is_validated' => 'boolean',
            'last_tested_at' => 'datetime',
            'capabilities' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function providerAccount(): BelongsTo
    {
        return $this->belongsTo(ProviderAccount::class);
    }

    public function agentAssignments(): HasMany
    {
        return $this->hasMany(AgentPhoneAssignment::class);
    }
}
