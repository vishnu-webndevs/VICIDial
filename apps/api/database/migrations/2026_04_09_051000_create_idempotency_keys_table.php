<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('user_id')->nullable();
            $table->string('scope_hash', 64)->unique();
            $table->string('idempotency_key', 120);
            $table->string('method', 10);
            $table->string('path', 255);
            $table->string('request_hash', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_body')->nullable();
            $table->timestamp('response_recorded_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'user_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
