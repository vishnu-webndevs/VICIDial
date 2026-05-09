<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GovernanceDrill extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'scenario',
        'status',
        'rto_minutes',
        'rpo_minutes',
        'provider_mode',
        'results',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'rto_minutes' => 'integer',
            'rpo_minutes' => 'integer',
            'results' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
