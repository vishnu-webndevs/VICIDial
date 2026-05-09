<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'thread_id',
        'direction',
        'status',
        'body',
        'media',
        'sent_by_user_id',
        'provider_message_id',
        'metadata',
        'sent_at',
        'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'media' => 'array',
            'metadata' => 'array',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(MessageThread::class, 'thread_id');
    }
}
