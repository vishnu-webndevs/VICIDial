<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\PlanFeature;
use Illuminate\Database\Seeder;

/**
 * Seeds the four public pricing plans shown on the landing page:
 *
 *   Free     – $0/mo   | $0/yr
 *   Starter  – $49/mo  | $470.40/yr  (20% off)
 *   Growth   – $149/mo | $1,430.40/yr (20% off) [Most Popular]
 *   Pro      – $399/mo | $3,830.40/yr (20% off)
 *
 * Annual price formula: monthly * 12 * 0.80 (20% discount)
 *
 * This seeder is fully idempotent — safe to re-run after a fresh migration.
 */
class PlanCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            // ── Free ─────────────────────────────────────────────────────────
            [
                'slug'                     => 'free',
                'name'                     => 'Free',
                'description'              => 'Get started at no cost',
                'price_monthly'            => 0.00,
                'price_yearly'             => 0.00,
                'monthly_price_cents'      => 0,
                'yearly_price_cents'       => 0,
                'trial_days'               => 0,
                'api_quota_monthly'        => 5_000,
                'call_minutes_monthly'     => 100,
                'webhook_events_monthly'   => 1_000,
                'is_active'                => true,
                'is_public'                => true,
                'sort_order'               => 0,
                'features' => [
                    ['key' => 'max_agents',            'type' => 'limit',   'value' => '1',     'label' => '1 agent'],
                    ['key' => 'max_campaigns',         'type' => 'limit',   'value' => '1',     'label' => '1 active campaign'],
                    ['key' => 'basic_analytics',       'type' => 'boolean', 'value' => 'true',  'label' => 'Basic analytics'],
                    ['key' => 'advanced_analytics',    'type' => 'boolean', 'value' => 'false', 'label' => 'Advanced analytics'],
                    ['key' => 'retry_automation',      'type' => 'boolean', 'value' => 'false', 'label' => 'Retry automation'],
                    ['key' => 'multi_tenant_isolation','type' => 'boolean', 'value' => 'false', 'label' => 'Multi-tenant isolation'],
                    ['key' => 'priority_support',      'type' => 'boolean', 'value' => 'false', 'label' => 'Priority support'],
                    ['key' => 'custom_integrations',   'type' => 'boolean', 'value' => 'false', 'label' => 'Custom integrations'],
                    ['key' => 'can_use_api',            'type' => 'boolean', 'value' => 'false', 'label' => 'API Access'],
                ],
            ],

            // ── Starter ──────────────────────────────────────────────────────
            [
                'slug'                     => 'starter',
                'name'                     => 'Starter',
                'description'              => 'Best for first outbound team',
                'price_monthly'            => 49.00,
                'price_yearly'             => 470.40,   // 49 * 12 * 0.80
                'monthly_price_cents'      => 4900,
                'yearly_price_cents'       => 47040,
                'trial_days'               => 14,
                'api_quota_monthly'        => 50_000,
                'call_minutes_monthly'     => 1_000,
                'webhook_events_monthly'   => 10_000,
                'is_active'                => true,
                'is_public'                => true,
                'sort_order'               => 1,
                'features' => [
                    ['key' => 'max_agents',            'type' => 'limit',   'value' => '5',     'label' => 'Up to 5 agents'],
                    ['key' => 'max_campaigns',         'type' => 'limit',   'value' => '1',     'label' => '1 active campaign'],
                    ['key' => 'basic_analytics',       'type' => 'boolean', 'value' => 'true',  'label' => 'Basic analytics'],
                    ['key' => 'advanced_analytics',    'type' => 'boolean', 'value' => 'false', 'label' => 'Advanced analytics'],
                    ['key' => 'retry_automation',      'type' => 'boolean', 'value' => 'false', 'label' => 'Retry automation'],
                    ['key' => 'multi_tenant_isolation','type' => 'boolean', 'value' => 'false', 'label' => 'Multi-tenant isolation'],
                    ['key' => 'priority_support',      'type' => 'boolean', 'value' => 'false', 'label' => 'Priority support'],
                    ['key' => 'custom_integrations',   'type' => 'boolean', 'value' => 'false', 'label' => 'Custom integrations'],
                    ['key' => 'can_use_api',            'type' => 'boolean', 'value' => 'false', 'label' => 'API Access'],
                ],
            ],

            // ── Growth (Most Popular) ─────────────────────────────────────────
            [
                'slug'                     => 'growth',
                'name'                     => 'Growth',
                'description'              => 'For scaling conversions',
                'price_monthly'            => 149.00,
                'price_yearly'             => 1430.40,  // 149 * 12 * 0.80
                'monthly_price_cents'      => 14900,
                'yearly_price_cents'       => 143040,
                'trial_days'               => 14,
                'api_quota_monthly'        => 200_000,
                'call_minutes_monthly'     => 5_000,
                'webhook_events_monthly'   => 50_000,
                'is_active'                => true,
                'is_public'                => true,
                'sort_order'               => 2,
                'features' => [
                    ['key' => 'max_agents',            'type' => 'limit',   'value' => '25',    'label' => 'Up to 25 agents'],
                    ['key' => 'max_campaigns',         'type' => 'limit',   'value' => '-1',    'label' => 'Unlimited campaigns'],
                    ['key' => 'basic_analytics',       'type' => 'boolean', 'value' => 'true',  'label' => 'Basic analytics'],
                    ['key' => 'advanced_analytics',    'type' => 'boolean', 'value' => 'true',  'label' => 'Advanced analytics'],
                    ['key' => 'retry_automation',      'type' => 'boolean', 'value' => 'true',  'label' => 'Retry automation'],
                    ['key' => 'multi_tenant_isolation','type' => 'boolean', 'value' => 'false', 'label' => 'Multi-tenant isolation'],
                    ['key' => 'priority_support',      'type' => 'boolean', 'value' => 'false', 'label' => 'Priority support'],
                    ['key' => 'custom_integrations',   'type' => 'boolean', 'value' => 'false', 'label' => 'Custom integrations'],
                    ['key' => 'can_use_api',            'type' => 'boolean', 'value' => 'true',  'label' => 'API Access'],
                ],
            ],

            // ── Pro ───────────────────────────────────────────────────────────
            [
                'slug'                     => 'pro',
                'name'                     => 'Pro',
                'description'              => 'For multi-team performance',
                'price_monthly'            => 399.00,
                'price_yearly'             => 3830.40,  // 399 * 12 * 0.80
                'monthly_price_cents'      => 39900,
                'yearly_price_cents'       => 383040,
                'trial_days'               => 14,
                'api_quota_monthly'        => 1_000_000,
                'call_minutes_monthly'     => 20_000,
                'webhook_events_monthly'   => 200_000,
                'is_active'                => true,
                'is_public'                => true,
                'sort_order'               => 3,
                'features' => [
                    ['key' => 'max_agents',            'type' => 'limit',   'value' => '-1',    'label' => 'Unlimited agents'],
                    ['key' => 'max_campaigns',         'type' => 'limit',   'value' => '-1',    'label' => 'Unlimited campaigns'],
                    ['key' => 'basic_analytics',       'type' => 'boolean', 'value' => 'true',  'label' => 'Basic analytics'],
                    ['key' => 'advanced_analytics',    'type' => 'boolean', 'value' => 'true',  'label' => 'Advanced analytics'],
                    ['key' => 'retry_automation',      'type' => 'boolean', 'value' => 'true',  'label' => 'Retry automation'],
                    ['key' => 'multi_tenant_isolation','type' => 'boolean', 'value' => 'true',  'label' => 'Multi-tenant isolation'],
                    ['key' => 'priority_support',      'type' => 'boolean', 'value' => 'true',  'label' => 'Priority support'],
                    ['key' => 'custom_integrations',   'type' => 'boolean', 'value' => 'true',  'label' => 'Custom integrations'],
                    ['key' => 'can_use_api',            'type' => 'boolean', 'value' => 'true',  'label' => 'API Access'],
                ],
            ],
        ];

        foreach ($plans as $planData) {
            $features = $planData['features'];
            unset($planData['features']);

            $plan = Plan::query()->updateOrCreate(
                ['slug' => $planData['slug']],
                array_merge($planData, ['billing_cycle' => 'monthly'])
            );

            foreach ($features as $feature) {
                PlanFeature::query()->updateOrCreate(
                    ['plan_id' => $plan->id, 'key' => $feature['key']],
                    [
                        'type'  => $feature['type'],
                        'value' => $feature['value'],
                        'label' => $feature['label'],
                    ]
                );
            }

            $monthly = $planData['price_monthly'];
            $yearly  = $planData['price_yearly'];
            $this->command->info("  Plan seeded: {$plan->name} (\${$monthly}/mo | \${$yearly}/yr)");
        }

        // Retire the old "business" slug if it exists — replaced by "pro".
        Plan::query()->where('slug', 'business')->update(['is_public' => false, 'is_active' => false]);

        // Keep "enterprise" in the DB but hidden from public listing.
        Plan::query()->where('slug', 'enterprise')->update(['is_public' => false]);

        $this->command->info('');
        $this->command->info('  Plan catalog seeded. Annual prices reflect 20% discount off monthly * 12.');
        $this->command->info('');
    }
}
