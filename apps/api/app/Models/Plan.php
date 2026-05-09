<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Plan extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'slug',
        'name',
        'description',
        'price_monthly',
        'price_yearly',
        'billing_cycle',
        'monthly_price_cents',
        'yearly_price_cents',
        'trial_days',
        'api_quota_monthly',
        'call_minutes_monthly',
        'webhook_events_monthly',
        'is_active',
        'is_public',
        'sort_order',
        'stripe_product_id',
        'stripe_price_monthly_id',
        'stripe_price_yearly_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_public' => 'boolean',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function features(): HasMany
    {
        return $this->hasMany(PlanFeature::class);
    }

    public function tenantPlans(): HasMany
    {
        return $this->hasMany(TenantPlan::class);
    }

    public function getLimit(string $featureKey): int
    {
        $feature = $this->featureMap()->get($featureKey);
        if (! $feature) {
            return -1;
        }

        return (int) $feature->value;
    }

    public function isEnabled(string $featureKey): bool
    {
        $feature = $this->featureMap()->get($featureKey);
        if (! $feature) {
            return true;
        }

        if ($feature->type === 'boolean') {
            return filter_var($feature->value, FILTER_VALIDATE_BOOLEAN);
        }

        return (int) $feature->value !== 0;
    }

    public function featureMap(): Collection
    {
        if (! SafeSchema::hasTable('plan_features')) {
            return collect();
        }

        if (! $this->relationLoaded('features')) {
            $this->load('features');
        }

        return $this->features->keyBy('key');
    }
}

class SafeSchema
{
    public static function hasTable(string $table): bool
    {
        static $cache = [];
        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }

        try {
            $cache[$table] = DB::getSchemaBuilder()->hasTable($table);
        } catch (\Throwable) {
            $cache[$table] = false;
        }

        return $cache[$table];
    }
}
