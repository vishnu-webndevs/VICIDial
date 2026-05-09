<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadDisposition extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'lead_id',
        'call_session_id',
        'agent_id',
        'disposition',
        'notes',
        'callback_at',
        'auto_rescheduled',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'callback_at' => 'datetime',
            'auto_rescheduled' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function callSession(): BelongsTo
    {
        return $this->belongsTo(CallSession::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }
}
