<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_reception_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('caller_number', 30);
            $table->text('transcript');
            $table->decimal('confidence_threshold', 5, 2)->nullable();
            $table->string('decision', 40);
            $table->decimal('confidence', 5, 2)->nullable();
            $table->text('captured_message')->nullable();
            $table->string('recommended_route', 120)->nullable();
            $table->string('provider_mode', 20)->default('mock');
            $table->json('metadata')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'caller_number']);
            $table->index(['tenant_id', 'decision']);
            $table->index(['tenant_id', 'processed_at']);
        });

        Schema::create('graph_availability_queries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->unsignedInteger('duration_minutes');
            $table->timestamp('window_from')->nullable();
            $table->timestamp('window_to')->nullable();
            $table->json('slots')->nullable();
            $table->string('provider_mode', 20)->default('mock');
            $table->timestamp('queried_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'window_from', 'window_to'], 'graph_availability_window_idx');
        });

        Schema::create('graph_bookings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('availability_query_id')->nullable();
            $table->string('external_booking_id', 120)->nullable();
            $table->string('calendar_event_id', 120)->nullable();
            $table->string('attendee_email', 255);
            $table->string('subject', 150);
            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();
            $table->boolean('confirmation_sent')->default(false);
            $table->string('provider_mode', 20)->default('mock');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('availability_query_id')->references('id')->on('graph_availability_queries')->nullOnDelete();
            $table->index(['tenant_id', 'start_at']);
            $table->index(['tenant_id', 'attendee_email']);
        });

        Schema::create('workflow_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('workflow_key', 80);
            $table->string('name', 120);
            $table->string('trigger_type', 40)->default('manual');
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->json('steps')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'workflow_key']);
            $table->index(['tenant_id', 'active']);
        });

        Schema::create('workflow_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('workflow_definition_id')->nullable();
            $table->string('workflow_key', 80);
            $table->string('status', 30)->default('queued');
            $table->string('provider_mode', 20)->default('mock');
            $table->json('input')->nullable();
            $table->json('output')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('workflow_definition_id')->references('id')->on('workflow_definitions')->nullOnDelete();
            $table->index(['tenant_id', 'workflow_key']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('report_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->date('from_date')->nullable();
            $table->date('to_date')->nullable();
            $table->json('kpis');
            $table->json('ai');
            $table->string('provider_mode', 20)->default('computed');
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'generated_at']);
        });

        Schema::create('retention_policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->unique();
            $table->unsignedInteger('retention_days');
            $table->boolean('pii_redaction_enabled')->default(false);
            $table->string('audit_export_email', 255)->nullable();
            $table->string('provider_mode', 20)->default('mock');
            $table->timestamp('effective_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        Schema::create('governance_drills', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('scenario', 40);
            $table->string('status', 30)->default('queued');
            $table->integer('rto_minutes')->nullable();
            $table->integer('rpo_minutes')->nullable();
            $table->string('provider_mode', 20)->default('mock');
            $table->json('results')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'scenario']);
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('whatsapp_opt_ins', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('counterparty_number', 30);
            $table->boolean('opted_in')->default(true);
            $table->string('source', 40)->default('inbound_message');
            $table->timestamp('last_changed_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'counterparty_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_opt_ins');
        Schema::dropIfExists('governance_drills');
        Schema::dropIfExists('retention_policies');
        Schema::dropIfExists('report_snapshots');
        Schema::dropIfExists('workflow_runs');
        Schema::dropIfExists('workflow_definitions');
        Schema::dropIfExists('graph_bookings');
        Schema::dropIfExists('graph_availability_queries');
        Schema::dropIfExists('ai_reception_events');
    }
};
