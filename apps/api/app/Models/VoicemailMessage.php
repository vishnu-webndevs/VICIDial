<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoicemailMessage extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'call_session_id',
        'contact_id',
        'project_id',
        'from_number',
        'to_number',
        'storage_url',
        'transcript',
        'status',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function callSession(): BelongsTo
    {
        return $this->belongsTo(CallSession::class);
    }
}
