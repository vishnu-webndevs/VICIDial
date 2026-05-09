<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'name',
        'site_address',
        'status',
        'priority',
        'owner_contact_id',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function ownerContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'owner_contact_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ProjectAssignment::class);
    }
}
