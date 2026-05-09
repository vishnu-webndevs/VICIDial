<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Extension extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'extension',
        'target_type',
        'target_id',
        'is_reserved',
    ];

    protected function casts(): array
    {
        return [
            'is_reserved' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
