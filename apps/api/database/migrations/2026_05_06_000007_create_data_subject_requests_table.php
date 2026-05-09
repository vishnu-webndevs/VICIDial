<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_subject_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('request_type', 20);
            $table->string('subject_type', 20);
            $table->string('subject_value', 255);
            $table->string('status', 30)->default('queued');
            $table->uuid('requested_by')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('result_path', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('requested_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'subject_type', 'subject_value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_subject_requests');
    }
};

