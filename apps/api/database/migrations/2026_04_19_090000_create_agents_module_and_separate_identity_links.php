<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('company_number', 32);
            $table->string('status', 20)->default('active');
            $table->json('metadata')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['tenant_id', 'company_number']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::table('agent_sessions', function (Blueprint $table) {
            $table->uuid('agent_id')->nullable()->after('tenant_id');
            $table->foreign('agent_id')->references('id')->on('agents')->cascadeOnDelete();
            $table->unique(['tenant_id', 'agent_id']);
            $table->index(['tenant_id', 'agent_id', 'status']);
        });

        Schema::table('agent_assignments', function (Blueprint $table) {
            $table->uuid('agent_id')->nullable()->after('user_id');
            $table->foreign('agent_id')->references('id')->on('agents')->cascadeOnDelete();
            $table->index(['tenant_id', 'agent_id', 'status']);
        });

        Schema::table('agent_phone_assignments', function (Blueprint $table) {
            $table->uuid('agent_id')->nullable()->after('user_id');
            $table->foreign('agent_id')->references('id')->on('agents')->cascadeOnDelete();
            $table->unique(['tenant_id', 'agent_id']);
            $table->index(['tenant_id', 'agent_id', 'status']);
        });

        Schema::table('campaign_agent_assignments', function (Blueprint $table) {
            $table->uuid('agent_id')->nullable()->after('user_id');
            $table->foreign('agent_id')->references('id')->on('agents')->cascadeOnDelete();
            $table->unique(['tenant_id', 'campaign_id', 'agent_id']);
            $table->index(['tenant_id', 'campaign_id', 'agent_id']);
        });

        Schema::table('dial_queue_items', function (Blueprint $table) {
            $table->uuid('assigned_agent_entity_id')->nullable()->after('assigned_agent_id');
            $table->foreign('assigned_agent_entity_id')->references('id')->on('agents')->nullOnDelete();
            $table->index(['tenant_id', 'assigned_agent_entity_id']);
        });
    }

    public function down(): void
    {
        Schema::table('dial_queue_items', function (Blueprint $table) {
            $table->dropForeign(['assigned_agent_entity_id']);
            $table->dropColumn('assigned_agent_entity_id');
        });

        Schema::table('campaign_agent_assignments', function (Blueprint $table) {
            $table->dropForeign(['agent_id']);
            $table->dropColumn('agent_id');
        });

        Schema::table('agent_phone_assignments', function (Blueprint $table) {
            $table->dropForeign(['agent_id']);
            $table->dropColumn('agent_id');
        });

        Schema::table('agent_assignments', function (Blueprint $table) {
            $table->dropForeign(['agent_id']);
            $table->dropColumn('agent_id');
        });

        Schema::table('agent_sessions', function (Blueprint $table) {
            $table->dropForeign(['agent_id']);
            $table->dropColumn('agent_id');
        });

        Schema::dropIfExists('agents');
    }
};
