<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CallLeg extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'call_session_id',
        'from_number',
        'to_number',
        'status',
        'started_at',
        'ended_at',
        'bridged_to_leg_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function callSession(): BelongsTo
    {
        return $this->belongsTo(CallSession::class);
    }
}
