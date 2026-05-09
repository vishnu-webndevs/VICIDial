<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CallEvent extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'call_session_id',
        'provider_account_id',
        'event_type',
        'provider_event_type',
        'status_after',
        'payload',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function callSession(): BelongsTo
    {
        return $this->belongsTo(CallSession::class);
    }

    public function providerAccount(): BelongsTo
    {
        return $this->belongsTo(ProviderAccount::class);
    }
}
