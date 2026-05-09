<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

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
            DB::unprepared('DROP TRIGGER IF EXISTS prevent_users_delete');
            DB::unprepared("
                CREATE TRIGGER prevent_users_delete
                BEFORE DELETE ON users
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Deleting rows from users is disabled.';
                END
            ");
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS prevent_users_delete');
    }
};
