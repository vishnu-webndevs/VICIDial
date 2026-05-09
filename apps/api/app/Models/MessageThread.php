<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MessageThread extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'channel',
        'counterparty_number',
        'contact_id',
        'project_id',
        'assigned_user_id',
        'status',
        'priority',
        'last_message_at',
        'first_inbound_at',
        'first_outbound_at',
        'first_response_due_at',
        'resolution_due_at',
        'sla_first_response_breached_at',
        'sla_resolution_breached_at',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'first_inbound_at' => 'datetime',
            'first_outbound_at' => 'datetime',
            'first_response_due_at' => 'datetime',
            'resolution_due_at' => 'datetime',
            'sla_first_response_breached_at' => 'datetime',
            'sla_resolution_breached_at' => 'datetime',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'thread_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
