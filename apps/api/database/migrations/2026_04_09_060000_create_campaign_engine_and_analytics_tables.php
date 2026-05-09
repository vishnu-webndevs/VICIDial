<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('created_by')->nullable();
            $table->uuid('preferred_provider_account_id')->nullable();
            $table->string('name', 255);
            $table->string('type', 20)->default('auto');
            $table->string('status', 30)->default('draft');
            $table->string('lead_list_name', 255)->nullable();
            $table->string('schedule_window', 255)->nullable();
            $table->unsignedInteger('retry_limit')->default(2);
            $table->unsignedInteger('queue_size')->default(50);
            $table->unsignedInteger('calls_per_minute')->default(20);
            $table->unsignedInteger('priority')->default(0);
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('preferred_provider_account_id')->references('id')->on('provider_accounts')->nullOnDelete();
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'updated_at']);
        });

        Schema::create('campaign_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('campaign_id');
            $table->uuid('started_by')->nullable();
            $table->uuid('paused_by')->nullable();
            $table->string('status', 30)->default('queued');
            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('queued_items')->default(0);
            $table->unsignedInteger('completed_items')->default(0);
            $table->unsignedInteger('failed_items')->default(0);
            $table->unsignedInteger('retried_items')->default(0);
            $table->unsignedInteger('calls_dispatched')->default(0);
            $table->unsignedInteger('calls_connected')->default(0);
            $table->unsignedInteger('calls_failed')->default(0);
            $table->unsignedInteger('calls_per_minute')->default(20);
            $table->unsignedInteger('calls_dispatched_in_window')->default(0);
            $table->timestamp('pacing_window_started_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('stopped_at')->nullable();
            $table->timestamp('last_tick_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('campaign_id')->references('id')->on('campaigns')->cascadeOnDelete();
            $table->foreign('started_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('paused_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['tenant_id', 'campaign_id', 'status']);
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('dial_queue_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('campaign_id');
            $table->uuid('campaign_run_id');
            $table->uuid('lead_id');
            $table->uuid('assigned_agent_id')->nullable();
            $table->uuid('last_call_session_id')->nullable();
            $table->unsignedInteger('priority')->default(0);
            $table->unsignedInteger('attempt_count')->default(0);
            $table->unsignedInteger('max_attempts')->default(2);
            $table->string('status', 30)->default('pending');
            $table->string('failure_reason', 255)->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('enqueued_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('campaign_id')->references('id')->on('campaigns')->cascadeOnDelete();
            $table->foreign('campaign_run_id')->references('id')->on('campaign_runs')->cascadeOnDelete();
            $table->foreign('lead_id')->references('id')->on('leads')->cascadeOnDelete();
            $table->foreign('assigned_agent_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('last_call_session_id')->references('id')->on('call_sessions')->nullOnDelete();
            $table->index(['tenant_id', 'campaign_run_id', 'status']);
            $table->index(['tenant_id', 'campaign_id', 'status', 'priority']);
            $table->index(['tenant_id', 'available_at']);
            $table->index(['tenant_id', 'lead_id']);
        });

        Schema::create('agent_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id');
            $table->string('status', 30)->default('offline');
            $table->unsignedInteger('capacity')->default(1);
            $table->unsignedInteger('active_assignments')->default(0);
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamp('available_since')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['tenant_id', 'user_id']);
            $table->index(['tenant_id', 'status', 'last_heartbeat_at']);
        });

        Schema::create('agent_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('campaign_id');
            $table->uuid('campaign_run_id');
            $table->uuid('dial_queue_item_id');
            $table->uuid('user_id');
            $table->uuid('agent_session_id');
            $table->string('status', 30)->default('assigned');
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('campaign_id')->references('id')->on('campaigns')->cascadeOnDelete();
            $table->foreign('campaign_run_id')->references('id')->on('campaign_runs')->cascadeOnDelete();
            $table->foreign('dial_queue_item_id')->references('id')->on('dial_queue_items')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('agent_session_id')->references('id')->on('agent_sessions')->cascadeOnDelete();
            $table->index(['tenant_id', 'campaign_run_id', 'status']);
            $table->index(['tenant_id', 'user_id', 'status']);
        });

        Schema::create('campaign_hourly_stats', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('campaign_id')->nullable();
            $table->timestamp('bucket_start');
            $table->unsignedInteger('total_calls')->default(0);
            $table->unsignedInteger('connected_calls')->default(0);
            $table->unsignedInteger('failed_calls')->default(0);
            $table->unsignedInteger('no_answer_calls')->default(0);
            $table->unsignedInteger('busy_calls')->default(0);
            $table->unsignedInteger('canceled_calls')->default(0);
            $table->unsignedInteger('total_duration_seconds')->default(0);
            $table->unsignedInteger('distinct_agents')->default(0);
            $table->unsignedInteger('distinct_leads')->default(0);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('campaign_id')->references('id')->on('campaigns')->nullOnDelete();
            $table->unique(['tenant_id', 'campaign_id', 'bucket_start']);
            $table->index(['tenant_id', 'bucket_start']);
        });

        Schema::create('campaign_daily_stats', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('campaign_id')->nullable();
            $table->date('bucket_date');
            $table->unsignedInteger('total_calls')->default(0);
            $table->unsignedInteger('connected_calls')->default(0);
            $table->unsignedInteger('failed_calls')->default(0);
            $table->unsignedInteger('no_answer_calls')->default(0);
            $table->unsignedInteger('busy_calls')->default(0);
            $table->unsignedInteger('canceled_calls')->default(0);
            $table->unsignedInteger('total_duration_seconds')->default(0);
            $table->unsignedInteger('distinct_agents')->default(0);
            $table->unsignedInteger('distinct_leads')->default(0);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('campaign_id')->references('id')->on('campaigns')->nullOnDelete();
            $table->unique(['tenant_id', 'campaign_id', 'bucket_date']);
            $table->index(['tenant_id', 'bucket_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_daily_stats');
        Schema::dropIfExists('campaign_hourly_stats');
        Schema::dropIfExists('agent_assignments');
        Schema::dropIfExists('agent_sessions');
        Schema::dropIfExists('dial_queue_items');
        Schema::dropIfExists('campaign_runs');
        Schema::dropIfExists('campaigns');
    }
};
