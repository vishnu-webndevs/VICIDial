<?php

namespace App\Jobs;

use App\Models\CampaignRun;
use App\Services\Campaigns\CampaignRunnerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunCampaignDialerJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(CampaignRunnerService $runnerService): void
    {
        CampaignRun::query()
            ->with('campaign')
            ->where('status', 'running')
            ->whereHas('campaign', fn ($query) => $query->where('status', 'running'))
            ->orderBy('last_tick_at')
            ->chunkById(100, function ($runs) use ($runnerService): void {
                foreach ($runs as $run) {
                    $runnerService->tick($run);
                }
            });
    }
}
