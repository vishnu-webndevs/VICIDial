<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_lists', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('lead_list_lead', function (Blueprint $table) {
            $table->uuid('lead_list_id');
            $table->uuid('lead_id');
            $table->uuid('tenant_id');
            $table->timestamp('attached_at')->nullable();

            $table->primary(['lead_list_id', 'lead_id']);
            $table->foreign('lead_list_id')->references('id')->on('lead_lists')->cascadeOnDelete();
            $table->foreign('lead_id')->references('id')->on('leads')->cascadeOnDelete();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'lead_id']);
        });

        Schema::create('dnc_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('phone', 30);
            $table->string('source', 50)->default('manual');
            $table->text('reason')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamp('effective_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['tenant_id', 'phone']);
            $table->index(['tenant_id', 'effective_at']);
        });

        Schema::create('lead_dispositions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('lead_id');
            $table->uuid('call_session_id')->nullable();
            $table->uuid('agent_id')->nullable();
            $table->string('disposition', 40);
            $table->text('notes')->nullable();
            $table->timestamp('callback_at')->nullable();
            $table->boolean('auto_rescheduled')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('lead_id')->references('id')->on('leads')->cascadeOnDelete();
            $table->foreign('call_session_id')->references('id')->on('call_sessions')->nullOnDelete();
            $table->foreign('agent_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['tenant_id', 'lead_id', 'created_at']);
            $table->index(['tenant_id', 'disposition', 'created_at']);
            $table->index(['tenant_id', 'callback_at']);
        });

        Schema::create('lead_timeline_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('lead_id');
            $table->string('event_type', 40);
            $table->uuid('related_id')->nullable();
            $table->string('related_type', 40)->nullable();
            $table->uuid('actor_id')->nullable();
            $table->text('content')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('lead_id')->references('id')->on('leads')->cascadeOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['tenant_id', 'lead_id', 'occurred_at']);
            $table->index(['tenant_id', 'event_type', 'occurred_at']);
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->uuid('owner_agent_id')->nullable()->after('owner_agent');
            $table->unsignedInteger('engagement_score')->default(0)->after('owner_agent_id');
            $table->unsignedInteger('call_attempts')->default(0)->after('engagement_score');
            $table->timestamp('last_contacted_at')->nullable()->after('call_attempts');
            $table->boolean('is_dnc')->default(false)->after('last_contacted_at');
            $table->json('last_disposition')->nullable()->after('is_dnc');

            $table->foreign('owner_agent_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['tenant_id', 'engagement_score']);
            $table->index(['tenant_id', 'is_dnc']);
        });

        Schema::table('lead_import_jobs', function (Blueprint $table) {
            $table->json('field_mapping')->nullable()->after('source_path');
            $table->json('target_list_ids')->nullable()->after('field_mapping');
            $table->boolean('skip_duplicates')->default(true)->after('target_list_ids');
            $table->boolean('skip_dnc')->default(true)->after('skip_duplicates');
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->boolean('auto_pause_when_no_agents')->default(true)->after('calls_per_minute');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn('auto_pause_when_no_agents');
        });

        Schema::table('lead_import_jobs', function (Blueprint $table) {
            $table->dropColumn(['field_mapping', 'target_list_ids', 'skip_duplicates', 'skip_dnc']);
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['owner_agent_id']);
            $table->dropColumn([
                'owner_agent_id',
                'engagement_score',
                'call_attempts',
                'last_contacted_at',
                'is_dnc',
                'last_disposition',
            ]);
        });

        Schema::dropIfExists('lead_timeline_items');
        Schema::dropIfExists('lead_dispositions');
        Schema::dropIfExists('dnc_entries');
        Schema::dropIfExists('lead_list_lead');
        Schema::dropIfExists('lead_lists');
    }
};
