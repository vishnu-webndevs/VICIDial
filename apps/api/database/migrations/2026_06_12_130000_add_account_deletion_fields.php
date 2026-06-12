<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->softDeletes();
            $table->timestamp('deletion_requested_at')->nullable()->after('last_login_ip');
            $table->timestamp('deletion_scheduled_at')->nullable()->after('deletion_requested_at');
            $table->text('deletion_reason')->nullable()->after('deletion_scheduled_at');

            $table->index('deletion_requested_at');
            $table->index('deletion_scheduled_at');
        });

        $this->recreateUsersDeleteTrigger();
    }

    public function down(): void
    {
        $this->recreateUsersDeleteTrigger(preventDeletes: true);

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['deletion_scheduled_at']);
            $table->dropIndex(['deletion_requested_at']);
            $table->dropColumn(['deletion_requested_at', 'deletion_scheduled_at', 'deletion_reason']);
            $table->dropSoftDeletes();
        });
    }

    private function recreateUsersDeleteTrigger(bool $preventDeletes = false): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS prevent_users_delete');
            if ($preventDeletes) {
                DB::unprepared("
                    CREATE TRIGGER prevent_users_delete
                    BEFORE DELETE ON users
                    FOR EACH ROW
                    BEGIN
                        SELECT RAISE(ABORT, 'Deleting rows from users is disabled.');
                    END
                ");
            } else {
                DB::unprepared("
                    CREATE TRIGGER prevent_users_delete
                    BEFORE DELETE ON users
                    FOR EACH ROW
                    WHEN NOT (
                        OLD.deleted_at IS NOT NULL
                        AND OLD.deletion_scheduled_at IS NOT NULL
                        AND OLD.deletion_scheduled_at < CURRENT_TIMESTAMP
                    )
                    BEGIN
                        SELECT RAISE(ABORT, 'Deleting rows from users is disabled.');
                    END
                ");
            }

            return;
        }

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        try {
            DB::unprepared('DROP TRIGGER IF EXISTS prevent_users_delete');
        } catch (\Throwable $e) {
            Log::warning('Skipping users delete trigger drop (insufficient privileges).', [
                'driver' => $driver,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        if ($preventDeletes) {
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

            return;
        }

        try {
            DB::unprepared("
                CREATE TRIGGER prevent_users_delete
                BEFORE DELETE ON users
                FOR EACH ROW
                BEGIN
                    IF NOT (
                        OLD.deleted_at IS NOT NULL
                        AND OLD.deletion_scheduled_at IS NOT NULL
                        AND OLD.deletion_scheduled_at < NOW()
                    ) THEN
                        SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Deleting rows from users is disabled.';
                    END IF;
                END
            ");
        } catch (\Throwable $e) {
            Log::warning('Skipping users delete trigger creation (insufficient privileges).', [
                'driver' => $driver,
                'error' => $e->getMessage(),
            ]);
        }
    }
};
