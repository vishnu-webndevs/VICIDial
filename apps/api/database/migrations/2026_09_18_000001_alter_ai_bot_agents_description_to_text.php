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
            DB::statement('ALTER TABLE ai_bot_agents MODIFY description TEXT NULL');
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
            DB::statement('ALTER TABLE ai_bot_agents MODIFY description VARCHAR(255) NULL');
        }
    }
};
