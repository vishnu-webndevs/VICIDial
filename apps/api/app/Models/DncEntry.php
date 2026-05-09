<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DncEntry extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'phone',
        'source',
        'reason',
        'created_by',
        'effective_at',
    ];

    protected function casts(): array
    {
        return [
            'effective_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
