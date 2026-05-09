<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataSubjectRequest extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'request_type',
        'subject_type',
        'subject_value',
        'status',
        'requested_by',
        'approved_by',
        'approved_at',
        'processed_at',
        'result_path',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'processed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}

