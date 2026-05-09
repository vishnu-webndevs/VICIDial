<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IdempotencyKey extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'scope_hash',
        'idempotency_key',
        'method',
        'path',
        'request_hash',
        'response_status',
        'response_body',
        'response_recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'response_body' => 'array',
            'response_recorded_at' => 'datetime',
        ];
    }
}
