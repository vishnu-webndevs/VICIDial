<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\UsageEvent;
use App\Models\UsageMeter;
use Illuminate\Support\Facades\DB;

class UsageQuotaService
{
    public function consume(string $tenantId, string $meterType, int $quantity, string $sourceType, ?string $sourceId = null): array
    {
        $subscription = Subscription::query()
            ->where('tenant_id', $tenantId)
            ->latest('created_at')
            ->first();

        if (! $subscription) {
            return ['allowed' => true, 'limit' => 0, 'remaining' => 0, 'reset_at' => null];
        }

        return DB::transaction(function () use ($tenantId, $meterType, $quantity, $sourceType, $sourceId, $subscription) {
            $meter = UsageMeter::query()
                ->where('tenant_id', $tenantId)
                ->where('subscription_id', $subscription->id)
                ->where('meter_type', $meterType)
                ->lockForUpdate()
                ->first();

            if (! $meter) {
                return ['allowed' => true, 'limit' => 0, 'remaining' => 0, 'reset_at' => null];
            }

            if ($meter->period_end && now()->greaterThan($meter->period_end)) {
                $meter->period_start = now()->startOfDay();
                $meter->period_end = now()->addMonthNoOverflow()->endOfDay();
                $meter->consumed_units = 0;
                $meter->save();
            }

            $nextConsumed = $meter->consumed_units + $quantity;
            $limit = (int) $meter->limit_units;
            if ($limit > 0 && $nextConsumed > $limit) {
                return [
                    'allowed' => false,
                    'limit' => $limit,
                    'remaining' => max($limit - (int) $meter->consumed_units, 0),
                    'reset_at' => optional($meter->period_end)->toISOString(),
                ];
            }

            $meter->consumed_units = $nextConsumed;
            $meter->save();

            UsageEvent::query()->create([
                'tenant_id' => $tenantId,
                'subscription_id' => $subscription->id,
                'meter_type' => $meterType,
                'quantity' => $quantity,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'metadata' => null,
                'occurred_at' => now(),
            ]);

            return [
                'allowed' => true,
                'limit' => $limit,
                'remaining' => $limit > 0 ? max($limit - $nextConsumed, 0) : 0,
                'reset_at' => optional($meter->period_end)->toISOString(),
            ];
        });
    }
}
