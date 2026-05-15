<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('provider_accounts', 'credentials_owner_user_id')) {
                $table->uuid('credentials_owner_user_id')->nullable()->after('credentials_encrypted');
                $table->index('credentials_owner_user_id');
                $table->foreign('credentials_owner_user_id')->references('id')->on('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('provider_accounts', function (Blueprint $table) {
            if (Schema::hasColumn('provider_accounts', 'credentials_owner_user_id')) {
                $table->dropForeign(['credentials_owner_user_id']);
                $table->dropIndex(['credentials_owner_user_id']);
                $table->dropColumn('credentials_owner_user_id');
            }
        });
    }
};

