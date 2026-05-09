<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('provider_account_id')->nullable();
            $table->uuid('initiated_by')->nullable();
            $table->string('direction', 20)->default('outbound');
            $table->string('status', 30)->default('queued');
            $table->string('provider_call_id', 100)->nullable();
            $table->string('from_number', 30)->nullable();
            $table->string('to_number', 30);
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedInteger('retry_count')->default(0);
            $table->string('failure_reason', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('provider_account_id')->references('id')->on('provider_accounts')->nullOnDelete();
            $table->foreign('initiated_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'provider_call_id']);
        });

        Schema::create('call_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('call_session_id');
            $table->uuid('provider_account_id')->nullable();
            $table->string('event_type', 100);
            $table->string('provider_event_type', 100)->nullable();
            $table->string('status_after', 30)->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('call_session_id')->references('id')->on('call_sessions')->cascadeOnDelete();
            $table->foreign('provider_account_id')->references('id')->on('provider_accounts')->nullOnDelete();
            $table->index(['tenant_id', 'occurred_at']);
            $table->index(['call_session_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_events');
        Schema::dropIfExists('call_sessions');
    }
};
