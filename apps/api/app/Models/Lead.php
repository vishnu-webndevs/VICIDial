<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $phone
 */
class Lead extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'import_job_id',
        'full_name',
        'phone',
        'email',
        'company',
        'status',
        'owner_agent',
        'owner_agent_id',
        'engagement_score',
        'call_attempts',
        'last_contacted_at',
        'is_dnc',
        'last_disposition',
        'next_follow_up_at',
        'tags',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'next_follow_up_at' => 'datetime',
            'last_contacted_at' => 'datetime',
            'engagement_score' => 'integer',
            'call_attempts' => 'integer',
            'is_dnc' => 'boolean',
            'last_disposition' => 'array',
            'tags' => 'array',
            'notes' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function importJob(): BelongsTo
    {
        return $this->belongsTo(LeadImportJob::class, 'import_job_id');
    }

    public function lists(): BelongsToMany
    {
        return $this->belongsToMany(LeadList::class, 'lead_list_lead')
            ->withPivot(['tenant_id', 'attached_at']);
    }

    public function dispositions(): HasMany
    {
        return $this->hasMany(LeadDisposition::class);
    }

    public function timelineItems(): HasMany
    {
        return $this->hasMany(LeadTimelineItem::class);
    }

    public function ownerAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_agent_id');
    }
}
