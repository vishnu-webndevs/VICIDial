<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LegalHold extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'scope_type',
        'scope_id',
        'phone',
        'reason',
        'active',
        'created_by',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'expires_at' => 'datetime',
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
}

