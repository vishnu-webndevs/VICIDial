<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignAgentAssignment extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'campaign_id',
        'agent_id',
        'provider_phone_number_id',
        'created_by',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function number(): BelongsTo
    {
        return $this->belongsTo(ProviderPhoneNumber::class, 'provider_phone_number_id');
    }
}
