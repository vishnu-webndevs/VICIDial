<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tenant extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'status',
        'suspended_at',
        'suspension_reason',
    ];

    protected function casts(): array
    {
        return [
            'suspended_at' => 'datetime',
        ];
    }

    public function settings(): HasOne
    {
        return $this->hasOne(TenantSetting::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function tenantPlans(): HasMany
    {
        return $this->hasMany(TenantPlan::class);
    }

    public function activePlan(): HasOne
    {
        return $this->hasOne(TenantPlan::class)
            ->where('status', 'active')
            ->latestOfMany('started_at');
    }

    public function planUsage(): HasMany
    {
        return $this->hasMany(PlanUsage::class);
    }

    public function activeSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->latestOfMany();
    }

    public function usageMeters(): HasMany
    {
        return $this->hasMany(UsageMeter::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function callSessions(): HasMany
    {
        return $this->hasMany(CallSession::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function leadImportJobs(): HasMany
    {
        return $this->hasMany(LeadImportJob::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }
}
