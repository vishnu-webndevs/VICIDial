<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Membership extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'role_id',
        'agency_unit_id',
        'team_unit_id',
        'status',
        'invited_by',
        'invitation_token',
        'invitation_expires_at',
        'joined_at',
    ];

    protected function casts(): array
    {
        return [
            'invitation_expires_at' => 'datetime',
            'joined_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function agencyUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class, 'agency_unit_id');
    }

    public function teamUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class, 'team_unit_id');
    }
}
