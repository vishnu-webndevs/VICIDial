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
        Schema::table('tenant_ai_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('tenant_ai_settings', 'google_calendar_service_account_json')) {
                $table->longText('google_calendar_service_account_json')->nullable()->after('default_model');
            }
            if (!Schema::hasColumn('tenant_ai_settings', 'google_calendar_id')) {
                $table->string('google_calendar_id', 255)->nullable()->after('google_calendar_service_account_json');
            }
        });

        Schema::table('ai_bot_agents', function (Blueprint $table) {
            if (!Schema::hasColumn('ai_bot_agents', 'calendar_id')) {
                $table->string('calendar_id', 255)->nullable()->after('agent_email');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenant_ai_settings', function (Blueprint $table) {
            if (Schema::hasColumn('tenant_ai_settings', 'google_calendar_service_account_json')) {
                $table->dropColumn('google_calendar_service_account_json');
            }
            if (Schema::hasColumn('tenant_ai_settings', 'google_calendar_id')) {
                $table->dropColumn('google_calendar_id');
            }
        });

        Schema::table('ai_bot_agents', function (Blueprint $table) {
            if (Schema::hasColumn('ai_bot_agents', 'calendar_id')) {
                $table->dropColumn('calendar_id');
            }
        });
    }
};
