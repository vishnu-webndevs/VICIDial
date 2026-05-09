<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_phone_numbers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('provider_account_id');
            $table->string('provider_number_sid', 64)->nullable();
            $table->string('phone_number', 20);
            $table->string('friendly_name', 120)->nullable();
            $table->string('status', 20)->default('inactive');
            $table->boolean('is_validated')->default(false);
            $table->timestamp('last_tested_at')->nullable();
            $table->json('capabilities')->nullable();
            $table->string('last_error_code', 100)->nullable();
            $table->string('last_error_message', 500)->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('provider_account_id')->references('id')->on('provider_accounts')->cascadeOnDelete();
            $table->unique(['provider_account_id', 'phone_number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'is_validated']);
            $table->index(['tenant_id', 'provider_account_id', 'status'], 'ppn_tenant_provider_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_phone_numbers');
    }
};
