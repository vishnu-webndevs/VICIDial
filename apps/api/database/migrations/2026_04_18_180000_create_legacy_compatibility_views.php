<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE OR REPLACE VIEW call_logs AS SELECT * FROM call_sessions');
        DB::statement('CREATE OR REPLACE VIEW dispositions AS SELECT * FROM lead_dispositions');
        DB::statement("CREATE OR REPLACE VIEW callbacks AS SELECT * FROM lead_dispositions WHERE disposition = 'callback'");
        DB::statement('CREATE OR REPLACE VIEW dnc_numbers AS SELECT * FROM dnc_entries');
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS call_logs');
        DB::statement('DROP VIEW IF EXISTS dispositions');
        DB::statement('DROP VIEW IF EXISTS callbacks');
        DB::statement('DROP VIEW IF EXISTS dnc_numbers');
    }
};
