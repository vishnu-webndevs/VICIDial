<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessagingOptOut extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'phone',
        'channel',
        'opted_out',
        'source',
        'reason',
        'last_changed_at',
    ];

    protected function casts(): array
    {
        return [
            'opted_out' => 'boolean',
            'last_changed_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}

