<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DialerLoopIncident extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'session_id',
        'request_id',
        'loop_signature',
        'occurred_at',
        'browser',
        'stack_trace',
        'actions',
        'metadata',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'browser' => 'array',
        'actions' => 'array',
        'metadata' => 'array',
    ];
}
