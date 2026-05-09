<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiReceptionEvent extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'caller_number',
        'transcript',
        'confidence_threshold',
        'decision',
        'confidence',
        'captured_message',
        'recommended_route',
        'provider_mode',
        'metadata',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'confidence_threshold' => 'decimal:2',
            'confidence' => 'decimal:2',
            'metadata' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
