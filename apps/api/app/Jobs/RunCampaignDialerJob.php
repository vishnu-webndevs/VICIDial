<?php

namespace App\Jobs;

use App\Models\CampaignRun;
use App\Services\Campaigns\CampaignRunnerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

class RunCampaignDialerJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(CampaignRunnerService $runnerService): void
    {
        // #region debug-point A:runner-job
        $this->debugReport('A', 'campaign.runner_job.start', [
            'ts' => now()->toISOString(),
        ]);
        // #endregion

        CampaignRun::query()
            ->with('campaign')
            ->where('status', 'running')
            ->whereHas('campaign', fn ($query) => $query->where('status', 'running'))
            ->orderBy('last_tick_at')
            ->chunkById(100, function ($runs) use ($runnerService): void {
                // #region debug-point B:runner-job-chunk
                $this->debugReport('B', 'campaign.runner_job.chunk', [
                    'count' => (int) $runs->count(),
                    'first_run_id' => (string) ($runs->first()?->id ?? ''),
                ]);
                // #endregion

                foreach ($runs as $run) {
                    $runnerService->tick($run);
                }
            });
    }

    private function debugReport(string $hypothesisId, string $event, array $data): void
    {
        $url = $this->debugServerUrl();
        if (! $url) {
            return;
        }

        try {
            Http::timeout(0.5)->post($url, [
                'sessionId' => 'auto-dialer-outbound-calls',
                'runId' => 'pre-fix',
                'hypothesisId' => $hypothesisId,
                'location' => 'RunCampaignDialerJob',
                'msg' => '[DEBUG] '.$event,
                'data' => $data,
                'ts' => (int) floor(microtime(true) * 1000),
            ]);
        } catch (\Throwable) {
        }
    }

    private function debugServerUrl(): ?string
    {
        static $cached = null;
        static $loaded = false;

        if ($loaded) {
            return $cached;
        }

        $loaded = true;

        try {
            $paths = [
                base_path('.dbg/auto-dialer-outbound-calls.env'),
                dirname(base_path()) . DIRECTORY_SEPARATOR . '.dbg' . DIRECTORY_SEPARATOR . 'auto-dialer-outbound-calls.env',
            ];
            foreach ($paths as $path) {
                if (is_string($path) && is_file($path)) {
                    $contents = (string) file_get_contents($path);
                    foreach (preg_split("/\r\n|\n|\r/", $contents) ?: [] as $line) {
                        if (str_starts_with($line, 'DEBUG_SERVER_URL=')) {
                            $cached = trim(substr($line, strlen('DEBUG_SERVER_URL=')));
                            break 2;
                        }
                    }
                }
            }
        } catch (\Throwable) {
        }

        if (! is_string($cached) || $cached === '') {
            $cached = env('DEBUG_SERVER_URL') ?: null;
        }

        return is_string($cached) && $cached !== '' ? $cached : null;
    }
}
