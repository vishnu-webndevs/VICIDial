<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_accounts', function (Blueprint $table) {
            $table->unsignedInteger('failover_priority')->default(100)->after('status');
            $table->boolean('is_fallback')->default(false)->after('failover_priority');
            $table->index(['tenant_id', 'failover_priority']);
        });

        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->string('default_caller_id', 30)->nullable()->after('alert_email');
            $table->string('voice_locale', 20)->default('en-US')->after('default_caller_id');
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id')->nullable();
            $table->string('type', 100);
            $table->string('title', 150);
            $table->string('message', 500);
            $table->json('metadata')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'read_at']);
            $table->index(['user_id', 'read_at']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');

        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->dropColumn(['default_caller_id', 'voice_locale']);
        });

        Schema::table('provider_accounts', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'failover_priority']);
            $table->dropColumn(['failover_priority', 'is_fallback']);
        });
    }
};
