<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageAttachment extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'message_id',
        'provider',
        'provider_url',
        'content_type',
        'file_name',
        'size_bytes',
        'sha256',
        'storage_path',
        'scan_status',
        'scan_result',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'scan_result' => 'array',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}

