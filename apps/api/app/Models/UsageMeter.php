<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsageMeter extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'subscription_id',
        'meter_type',
        'consumed_units',
        'limit_units',
        'period_start',
        'period_end',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
            'period_end' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
