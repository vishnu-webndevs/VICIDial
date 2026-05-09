<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProviderAccount extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'provider_type',
        'display_name',
        'credentials_encrypted',
        'status',
        'failover_priority',
        'is_fallback',
        'last_tested_at',
        'last_error_code',
        'last_error_message',
    ];

    protected function casts(): array
    {
        return [
            'credentials_encrypted' => 'encrypted:array',
            'failover_priority' => 'integer',
            'is_fallback' => 'boolean',
            'last_tested_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function phoneNumbers(): HasMany
    {
        return $this->hasMany(ProviderPhoneNumber::class);
    }
}
