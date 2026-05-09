<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GraphBooking extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'availability_query_id',
        'external_booking_id',
        'calendar_event_id',
        'attendee_email',
        'subject',
        'start_at',
        'end_at',
        'status',
        'canceled_at',
        'confirmation_sent',
        'provider_mode',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'canceled_at' => 'datetime',
            'confirmation_sent' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function availabilityQuery(): BelongsTo
    {
        return $this->belongsTo(GraphAvailabilityQuery::class, 'availability_query_id');
    }
}
