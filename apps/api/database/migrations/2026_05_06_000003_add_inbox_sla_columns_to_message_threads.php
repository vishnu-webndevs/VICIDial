<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_threads', function (Blueprint $table) {
            $table->string('priority', 20)->default('normal')->after('status');
            $table->timestamp('first_inbound_at')->nullable()->after('last_message_at');
            $table->timestamp('first_outbound_at')->nullable()->after('first_inbound_at');
            $table->timestamp('first_response_due_at')->nullable()->after('first_outbound_at');
            $table->timestamp('resolution_due_at')->nullable()->after('first_response_due_at');
            $table->timestamp('sla_first_response_breached_at')->nullable()->after('resolution_due_at');
            $table->timestamp('sla_resolution_breached_at')->nullable()->after('sla_first_response_breached_at');

            $table->index(['tenant_id', 'channel', 'status', 'priority'], 'threads_status_priority_idx');
            $table->index(['tenant_id', 'first_response_due_at'], 'threads_first_response_due_idx');
            $table->index(['tenant_id', 'resolution_due_at'], 'threads_resolution_due_idx');
        });
    }

    public function down(): void
    {
        Schema::table('message_threads', function (Blueprint $table) {
            $table->dropIndex('threads_status_priority_idx');
            $table->dropIndex('threads_first_response_due_idx');
            $table->dropIndex('threads_resolution_due_idx');
            $table->dropColumn([
                'priority',
                'first_inbound_at',
                'first_outbound_at',
                'first_response_due_at',
                'resolution_due_at',
                'sla_first_response_breached_at',
                'sla_resolution_breached_at',
            ]);
        });
    }
};

