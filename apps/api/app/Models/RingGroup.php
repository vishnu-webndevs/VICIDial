<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RingGroup extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'name',
        'strategy',
        'ring_timeout_seconds',
        'max_queue_seconds',
        'max_retries',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(RingGroupMember::class);
    }
}
