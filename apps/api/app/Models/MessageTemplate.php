<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageTemplate extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'channel',
        'category',
        'key',
        'name',
        'body',
        'meta_template_id',
        'is_meta_approved',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_meta_approved' => 'boolean',
        ];
    }

    public function metaTemplate(): BelongsTo
    {
        return $this->belongsTo(MetaWhatsappTemplate::class, 'meta_template_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
