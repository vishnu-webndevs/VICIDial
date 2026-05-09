<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('provider_accounts')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE provider_accounts MODIFY credentials_encrypted LONGTEXT NOT NULL');
            return;
        }

        // Keep non-MySQL environments unchanged because JSON constraints differ by driver.
    }

    public function down(): void
    {
        if (! Schema::hasTable('provider_accounts')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE provider_accounts MODIFY credentials_encrypted JSON NOT NULL');
            return;
        }
    }
};
