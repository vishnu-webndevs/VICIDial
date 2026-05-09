<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams_approval_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('created_by_user_id')->nullable();
            $table->uuid('responded_by_user_id')->nullable();
            $table->string('token', 80)->unique();
            $table->string('title', 120);
            $table->text('message');
            $table->string('severity', 20)->default('info');
            $table->string('status', 20)->default('pending');
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->json('card_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('responded_by_user_id')->references('id')->on('users')->nullOnDelete();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teams_approval_requests');
    }
};

