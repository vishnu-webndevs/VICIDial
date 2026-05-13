<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS prevent_users_delete');
            DB::unprepared("
                CREATE TRIGGER prevent_users_delete
                BEFORE DELETE ON users
                FOR EACH ROW
                BEGIN
                    SELECT RAISE(ABORT, 'Deleting rows from users is disabled.');
                END
            ");

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            try {
                DB::unprepared('DROP TRIGGER IF EXISTS prevent_users_delete');
            } catch (\Throwable $e) {
                Log::warning('Skipping users delete trigger drop (insufficient privileges).', [
                    'driver' => $driver,
                    'error' => $e->getMessage(),
                ]);
            }

            try {
                DB::unprepared("
                    CREATE TRIGGER prevent_users_delete
                    BEFORE DELETE ON users
                    FOR EACH ROW
                    BEGIN
                        SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Deleting rows from users is disabled.';
                    END
                ");
            } catch (\Throwable $e) {
                Log::warning('Skipping users delete trigger creation (insufficient privileges).', [
                    'driver' => $driver,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function down(): void
    {
        try {
            DB::unprepared('DROP TRIGGER IF EXISTS prevent_users_delete');
        } catch (\Throwable) {
        }
    }
};
