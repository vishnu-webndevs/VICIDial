<?php

namespace App\Jobs;

use App\Models\UsageMeter;
use App\Models\UsageEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class IncrementBillingUsage implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;
    public int $backoff = 2; // Retry after 2 seconds on deadlock / failure
    public ?string $queue = 'telemetry';

    public function __construct(private readonly string $tenantId)
    {
    }

    public function handle(): void
    {
        try {
            $meter = UsageMeter::query()
                ->where('tenant_id', $this->tenantId)
                ->where('meter_type', 'api_requests')
                ->first();

            if ($meter) {
                if ($meter->period_end && now()->greaterThan($meter->period_end)) {
                    $meter->period_start = now()->startOfDay();
                    $meter->period_end = now()->addMonthNoOverflow()->endOfDay();
                    $meter->consumed_units = 0;
                    $meter->save();

                    // Forget cache keys to force fresh limits and starting count loading.
                    cache()->forget("usage_meter_cache:{$this->tenantId}");
                    cache()->forget("usage_meter_count:{$this->tenantId}");
                }

                $meter->increment('consumed_units');

                UsageEvent::query()->create([
                    'tenant_id' => $this->tenantId,
                    'subscription_id' => $meter->subscription_id,
                    'meter_type' => 'api_requests',
                    'quantity' => 1,
                    'source_type' => 'api_request',
                    'occurred_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Failed to increment billing usage in job: ' . $e->getMessage());
            throw $e; // Throw exception to trigger automatic retry by Laravel Queue
        }
    }
}
