<?php

namespace App\Console\Commands;

use App\Models\CallSession;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupStuckCalls extends Command
{
    protected $signature = 'calls:cleanup-stuck {--hours=1 : Hours old to consider stuck} {--force : Skip confirmation}';

    protected $description = 'Clean up stuck calls that are still in queued or ringing state';

    public function handle(): int
    {
        $hours = max(1, (int)$this->option('hours'));
        $force = (bool)$this->option('force');
        $cutoff = now()->subHours($hours);

        $stuckCalls = CallSession::query()
            ->whereIn('status', ['queued', 'ringing'])
            ->where('created_at', '<', $cutoff)
            ->get();

        if ($stuckCalls->isEmpty()) {
            $this->info('No stuck calls found.');
            return 0;
        }

        $this->info("Found {$stuckCalls->count()} stuck calls older than {$hours} hour(s):");
        foreach ($stuckCalls as $call) {
            $this->line("- {$call->id}: {$call->to_number} (status: {$call->status}, created: {$call->created_at})");
        }

        if (!$force && !$this->confirm('Do you want to mark these calls as canceled?')) {
            $this->info('Aborted.');
            return 0;
        }

        $count = 0;
        foreach ($stuckCalls as $call) {
            try {
                $call->status = 'canceled';
                $call->failure_reason = 'Stuck call cleaned up by system';
                $call->ended_at = now();
                $call->save();

                Log::info("Cleaned up stuck call: {$call->id}", ['to' => $call->to_number, 'old_status' => $call->getOriginal('status')]);
                $count++;
            } catch (\Throwable $e) {
                Log::error("Failed to clean up stuck call: {$call->id}", ['error' => $e->getMessage()]);
                $this->error("Failed to clean up call {$call->id}: {$e->getMessage()}");
            }
        }

        $this->info("Successfully cleaned up {$count} calls.");
        return 0;
    }
}