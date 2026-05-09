<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowDefinition extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'workflow_key',
        'name',
        'trigger_type',
        'description',
        'active',
        'steps',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'steps' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(WorkflowRun::class);
    }
}
