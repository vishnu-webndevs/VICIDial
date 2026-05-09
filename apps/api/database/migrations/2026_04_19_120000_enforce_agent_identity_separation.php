<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Remove legacy user-bound records so only agent-entity links remain.
        DB::table('agent_sessions')->whereNull('agent_id')->delete();
        DB::table('agent_assignments')->whereNull('agent_id')->delete();
        DB::table('agent_phone_assignments')->whereNull('agent_id')->delete();
        DB::table('campaign_agent_assignments')->whereNull('agent_id')->delete();

        Schema::table('agent_sessions', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropUnique(['tenant_id', 'user_id']);
            $table->dropColumn('user_id');
        });

        Schema::table('agent_assignments', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropIndex(['tenant_id', 'user_id', 'status']);
            $table->dropColumn('user_id');
        });

        Schema::table('agent_phone_assignments', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropUnique(['tenant_id', 'user_id']);
            $table->dropColumn('user_id');
        });

        Schema::table('campaign_agent_assignments', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropUnique(['tenant_id', 'campaign_id', 'user_id']);
            $table->dropIndex(['tenant_id', 'user_id']);
            $table->dropColumn('user_id');
        });

        Schema::table('dial_queue_items', function (Blueprint $table) {
            $table->dropForeign(['assigned_agent_id']);
            $table->dropColumn('assigned_agent_id');
        });
    }

    public function down(): void
    {
        Schema::table('dial_queue_items', function (Blueprint $table) {
            $table->uuid('assigned_agent_id')->nullable()->after('lead_id');
            $table->foreign('assigned_agent_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('campaign_agent_assignments', function (Blueprint $table) {
            $table->uuid('user_id')->nullable()->after('campaign_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['tenant_id', 'campaign_id', 'user_id']);
            $table->index(['tenant_id', 'user_id']);
        });

        Schema::table('agent_phone_assignments', function (Blueprint $table) {
            $table->uuid('user_id')->nullable()->after('tenant_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['tenant_id', 'user_id']);
        });

        Schema::table('agent_assignments', function (Blueprint $table) {
            $table->uuid('user_id')->nullable()->after('dial_queue_item_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['tenant_id', 'user_id', 'status']);
        });

        Schema::table('agent_sessions', function (Blueprint $table) {
            $table->uuid('user_id')->nullable()->after('tenant_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['tenant_id', 'user_id']);
        });
    }
};
