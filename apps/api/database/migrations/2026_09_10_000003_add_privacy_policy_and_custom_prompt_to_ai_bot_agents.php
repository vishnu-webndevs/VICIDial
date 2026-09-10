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
        if (Schema::hasTable('ai_bot_agents')) {
            Schema::table('ai_bot_agents', function (Blueprint $table) {
                if (!Schema::hasColumn('ai_bot_agents', 'privacy_policy')) {
                    $table->text('privacy_policy')->nullable()->after('system_instructions');
                }
                if (!Schema::hasColumn('ai_bot_agents', 'custom_knowledge_prompt')) {
                    $table->longText('custom_knowledge_prompt')->nullable()->after('knowledge_base');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ai_bot_agents')) {
            Schema::table('ai_bot_agents', function (Blueprint $table) {
                if (Schema::hasColumn('ai_bot_agents', 'privacy_policy')) {
                    $table->dropColumn('privacy_policy');
                }
                if (Schema::hasColumn('ai_bot_agents', 'custom_knowledge_prompt')) {
                    $table->dropColumn('custom_knowledge_prompt');
                }
            });
        }
    }
};
