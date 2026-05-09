<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'plan_id',
        'status',
        'billing_cycle',
        'current_period_start',
        'current_period_end',
        'trial_ends_at',
        'canceled_at',
        'stripe_customer_id',
        'stripe_subscription_id',
    ];

    protected function casts(): array
    {
        return [
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'trial_ends_at' => 'datetime',
            'canceled_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function usageMeters(): HasMany
    {
        return $this->hasMany(UsageMeter::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
