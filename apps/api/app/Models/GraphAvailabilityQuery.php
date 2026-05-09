<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GraphAvailabilityQuery extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'duration_minutes',
        'window_from',
        'window_to',
        'slots',
        'provider_mode',
        'queried_at',
    ];

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'window_from' => 'datetime',
            'window_to' => 'datetime',
            'slots' => 'array',
            'queried_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(GraphBooking::class, 'availability_query_id');
    }
}
