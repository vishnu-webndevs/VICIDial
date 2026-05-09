<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'display_name',
        'company',
        'role',
        'tags',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function phones(): HasMany
    {
        return $this->hasMany(ContactPhone::class);
    }
}
