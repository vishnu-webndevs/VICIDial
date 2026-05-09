<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_ai_artifacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('call_session_id');
            $table->string('status', 30)->default('queued');
            $table->longText('transcript')->nullable();
            $table->longText('summary')->nullable();
            $table->unsignedInteger('qa_score')->nullable();
            $table->string('provider_mode', 20)->default('mock');
            $table->json('metadata')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('call_session_id')->references('id')->on('call_sessions')->cascadeOnDelete();
            $table->unique(['tenant_id', 'call_session_id']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_ai_artifacts');
    }
};

