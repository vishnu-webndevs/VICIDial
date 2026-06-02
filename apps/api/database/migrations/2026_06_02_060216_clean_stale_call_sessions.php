<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Mark all call sessions that are stuck in active states but are older than 1 hour as failed
        DB::table('call_sessions')
            ->whereIn('status', ['queued', 'ringing', 'in_progress'])
            ->where('created_at', '<', now()->subHours(1))
            ->update([
                'status' => 'failed',
                'failure_reason' => 'stale_call_session',
                'ended_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op - we don't want to revert status updates
    }
};
