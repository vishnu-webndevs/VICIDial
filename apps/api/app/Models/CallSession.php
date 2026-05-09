<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CallSession extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'provider_account_id',
        'contact_id',
        'project_id',
        'initiated_by',
        'direction',
        'status',
        'runtime_state',
        'provider_call_id',
        'from_number',
        'to_number',
        'duration_seconds',
        'retry_count',
        'failure_reason',
        'routed_to',
        'routing_confidence',
        'recording_url',
        'recording_duration',
        'recording_tags',
        'recording_notes',
        'metadata',
        'started_at',
        'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'recording_tags' => 'array',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function providerAccount(): BelongsTo
    {
        return $this->belongsTo(ProviderAccount::class);
    }

    public function initiatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(CallEvent::class);
    }

    public function legs(): HasMany
    {
        return $this->hasMany(CallLeg::class);
    }
}
