<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stripe_webhook_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('event_id', 100)->unique();
            $table->string('event_type', 100);
            $table->string('status', 20)->default('pending');
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->string('error_message', 1000)->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_webhook_events');
    }
};
