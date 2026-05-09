<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RetentionPolicy extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'retention_days',
        'pii_redaction_enabled',
        'audit_export_email',
        'provider_mode',
        'effective_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'retention_days' => 'integer',
            'pii_redaction_enabled' => 'boolean',
            'effective_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
