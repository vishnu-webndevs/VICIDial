<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportSnapshot extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'from_date',
        'to_date',
        'kpis',
        'ai',
        'provider_mode',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'from_date' => 'date',
            'to_date' => 'date',
            'kpis' => 'array',
            'ai' => 'array',
            'generated_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
