<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignDailyStat extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'campaign_id',
        'bucket_date',
        'total_calls',
        'connected_calls',
        'failed_calls',
        'no_answer_calls',
        'busy_calls',
        'canceled_calls',
        'total_duration_seconds',
        'distinct_agents',
        'distinct_leads',
    ];

    protected function casts(): array
    {
        return [
            'bucket_date' => 'date',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
