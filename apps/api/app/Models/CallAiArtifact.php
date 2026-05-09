<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CallAiArtifact extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'call_session_id',
        'status',
        'transcript',
        'summary',
        'qa_score',
        'provider_mode',
        'metadata',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'qa_score' => 'integer',
            'metadata' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function callSession(): BelongsTo
    {
        return $this->belongsTo(CallSession::class);
    }
}

