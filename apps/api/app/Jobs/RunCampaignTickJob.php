<?php

namespace App\Jobs;

use App\Models\CampaignRun;
use App\Services\Campaigns\CampaignRunnerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunCampaignTickJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $campaignRunId)
    {
    }

    public function handle(CampaignRunnerService $runnerService): void
    {
        $run = CampaignRun::query()->with('campaign')->find($this->campaignRunId);
        if (! $run) {
            return;
        }

        if ($run->status !== 'running' || $run->campaign?->status !== 'running') {
            return;
        }

        $runnerService->tick($run);

        $run->refresh();
        if ($run->status === 'running' && $run->campaign?->status === 'running') {
            self::dispatch($run->id)->delay(now()->addSeconds(5));
        }
    }
}
