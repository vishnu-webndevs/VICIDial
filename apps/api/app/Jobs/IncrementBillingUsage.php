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

    public function __construct(private readonly string $tenantId)
    {
        $this->onQueue('telemetry');
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
