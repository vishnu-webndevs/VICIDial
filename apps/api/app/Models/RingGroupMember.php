<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RingGroupMember extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'ring_group_id',
        'target_type',
        'target_id',
        'external_number',
        'priority',
    ];

    public function ringGroup(): BelongsTo
    {
        return $this->belongsTo(RingGroup::class);
    }
}
