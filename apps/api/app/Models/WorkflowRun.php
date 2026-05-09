<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowRun extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'workflow_definition_id',
        'workflow_key',
        'status',
        'provider_mode',
        'input',
        'output',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'input' => 'array',
            'output' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function workflowDefinition(): BelongsTo
    {
        return $this->belongsTo(WorkflowDefinition::class);
    }
}
