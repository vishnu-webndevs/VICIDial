<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('provider_type', 30);
            $table->string('display_name', 100);
            $table->json('credentials_encrypted');
            $table->string('status', 20)->default('pending');
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_error_code', 100)->nullable();
            $table->string('last_error_message', 500)->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'provider_type']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_accounts');
    }
};
