<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('ai_bot_agents') && !Schema::hasColumn('ai_bot_agents', 'agent_email')) {
            Schema::table('ai_bot_agents', function (Blueprint $table) {
                $table->string('agent_email', 255)->nullable()->after('name');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ai_bot_agents') && Schema::hasColumn('ai_bot_agents', 'agent_email')) {
            Schema::table('ai_bot_agents', function (Blueprint $table) {
                $table->dropColumn('agent_email');
            });
        }
    }
};
