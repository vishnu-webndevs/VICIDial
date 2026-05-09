<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('message_id');
            $table->string('provider', 30)->default('twilio');
            $table->string('provider_url', 500)->nullable();
            $table->string('content_type', 120)->nullable();
            $table->string('file_name', 255)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->string('storage_path', 500)->nullable();
            $table->string('scan_status', 30)->default('pending');
            $table->json('scan_result')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('message_id')->references('id')->on('messages')->cascadeOnDelete();
            $table->index(['tenant_id', 'message_id']);
            $table->index(['tenant_id', 'scan_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_attachments');
    }
};

