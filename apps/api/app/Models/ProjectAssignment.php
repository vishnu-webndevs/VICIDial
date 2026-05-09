<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectAssignment extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'project_id',
        'engineer_id',
        'role',
        'active_from',
        'active_to',
    ];

    protected function casts(): array
    {
        return [
            'active_from' => 'datetime',
            'active_to' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function engineer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'engineer_id');
    }
}
