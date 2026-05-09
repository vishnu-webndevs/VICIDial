<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_import_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('created_by')->nullable();
            $table->string('file_name', 255);
            $table->string('source_path', 500);
            $table->string('status', 20)->default('queued');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('successful_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);
            $table->json('error_report')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('leads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('import_job_id')->nullable();
            $table->string('full_name', 255);
            $table->string('phone', 30);
            $table->string('email', 255)->nullable();
            $table->string('company', 255)->nullable();
            $table->string('status', 30)->default('new');
            $table->string('owner_agent', 255)->nullable();
            $table->timestamp('next_follow_up_at')->nullable();
            $table->json('tags')->nullable();
            $table->json('notes')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('import_job_id')->references('id')->on('lead_import_jobs')->nullOnDelete();
            $table->index(['tenant_id', 'updated_at']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
        Schema::dropIfExists('lead_import_jobs');
    }
};
