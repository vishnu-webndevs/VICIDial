<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('ai_bot_agents')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE ai_bot_agents MODIFY system_instructions LONGTEXT NULL');
            DB::statement('ALTER TABLE ai_bot_agents MODIFY privacy_policy LONGTEXT NULL');
            DB::statement('ALTER TABLE ai_bot_agents MODIFY custom_knowledge_prompt LONGTEXT NULL');
            DB::statement('ALTER TABLE ai_bot_agents MODIFY fallback_message TEXT NULL');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('ai_bot_agents')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE ai_bot_agents MODIFY system_instructions TEXT NULL');
            DB::statement('ALTER TABLE ai_bot_agents MODIFY privacy_policy TEXT NULL');
        }
    }
};
