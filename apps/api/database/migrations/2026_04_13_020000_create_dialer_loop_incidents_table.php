<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dialer_loop_incidents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('user_id')->nullable()->index();
            $table->string('session_id', 100)->index();
            $table->string('request_id', 100)->nullable()->index();
            $table->string('loop_signature', 190)->index();
            $table->timestamp('occurred_at');
            $table->json('browser');
            $table->longText('stack_trace')->nullable();
            $table->json('actions');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dialer_loop_incidents');
    }
};
