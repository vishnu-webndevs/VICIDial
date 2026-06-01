<?php

namespace App\Jobs;

use App\Models\CampaignRun;
use App\Services\Campaigns\CampaignRunnerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

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
            $this->writeTickLog('info', 'RunCampaignTickJob skipped (run missing).', [
                'campaign_run_id' => $this->campaignRunId,
            ]);
            return;
        }

        if ($run->status !== 'running' || $run->campaign?->status !== 'running') {
            $this->writeTickLog('info', 'RunCampaignTickJob skipped (not running).', [
                'tenant_id' => (string) ($run->tenant_id ?? ''),
                'campaign_id' => (string) ($run->campaign_id ?? ''),
                'campaign_run_id' => (string) $run->id,
                'run_status' => (string) $run->status,
                'campaign_status' => (string) ($run->campaign?->status ?? ''),
            ]);
            return;
        }

        $campaign = $run->campaign;
        if ($campaign && !$runnerService->isWithinAllowedCallingWindow($campaign)) {
            $this->writeTickLog('info', 'RunCampaignTickJob waiting (outside calling window).', [
                'tenant_id' => (string) ($run->tenant_id ?? ''),
                'campaign_id' => (string) ($run->campaign_id ?? ''),
                'campaign_run_id' => (string) $run->id,
            ]);

            self::dispatch($run->id)->delay(now()->addSeconds(60));
            return;
        }

        $this->writeTickLog('info', 'RunCampaignTickJob handle hit.', [
            'tenant_id' => (string) ($run->tenant_id ?? ''),
            'campaign_id' => (string) ($run->campaign_id ?? ''),
            'campaign_run_id' => (string) $run->id,
        ]);
        $runnerService->tick($run);

        $run->refresh();
        if ($run->status === 'running' && $run->campaign?->status === 'running') {
            self::dispatch($run->id)->delay(now()->addSeconds(5));
        } else {
            $this->writeTickLog('info', 'RunCampaignTickJob stopped rescheduling.', [
                'tenant_id' => (string) ($run->tenant_id ?? ''),
                'campaign_id' => (string) ($run->campaign_id ?? ''),
                'campaign_run_id' => (string) $run->id,
                'run_status' => (string) $run->status,
                'campaign_status' => (string) ($run->campaign?->status ?? ''),
            ]);
        }
    }

    private function writeTickLog(string $level, string $message, array $context): void
    {
        try {
            Log::log($level, $message, $context);
        } catch (\Throwable) {
        }

        try {
            $line = sprintf(
                "[%s] %s: %s %s\n",
                now()->format('Y-m-d H:i:s'),
                strtoupper($level),
                $message,
                json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
            @file_put_contents(storage_path('logs/laravel.log'), $line, FILE_APPEND);
        } catch (\Throwable) {
        }
    }
}
