<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeadImportJob extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'created_by',
        'file_name',
        'source_path',
        'field_mapping',
        'target_list_ids',
        'skip_duplicates',
        'skip_dnc',
        'status',
        'total_rows',
        'processed_rows',
        'successful_rows',
        'failed_rows',
        'error_report',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'error_report' => 'array',
            'field_mapping' => 'array',
            'target_list_ids' => 'array',
            'skip_duplicates' => 'boolean',
            'skip_dnc' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'import_job_id');
    }
}
