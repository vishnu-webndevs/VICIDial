<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('campaigns')
            ->whereIn('type', ['auto', 'manual'])
            ->update(['type' => 'outbound_call']);
    }

    public function down(): void
    {
        DB::table('campaigns')
            ->where('type', 'outbound_call')
            ->update(['type' => 'auto']);
    }
};

