<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('meta_whatsapp_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->foreignId('provider_account_id')->constrained('provider_accounts')->onDelete('cascade');
            $table->string('meta_template_id')->unique(); // Meta's template ID
            $table->string('template_name');
            $table->string('category')->nullable();
            $table->string('language', 10)->default('en');
            $table->string('status')->default('PENDING_REVIEW'); // APPROVED, PENDING_REVIEW, etc.
            $table->json('components'); // Full Meta components array
            $table->boolean('has_header')->default(false);
            $table->boolean('has_body')->default(true);
            $table->boolean('has_footer')->default(false);
            $table->boolean('has_buttons')->default(false);
            $table->integer('button_count')->default(0);
            $table->integer('variable_count')->default(0);
            $table->json('raw_payload'); // Full Meta API response
            $table->timestamp('synced_at');
            $table->timestamp('last_updated_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'provider_account_id']);
            $table->index(['meta_template_id']);
            $table->index(['status']);
        });

        Schema::create('meta_template_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->foreignId('provider_account_id')->constrained('provider_accounts')->onDelete('cascade');
            $table->timestamp('sync_started_at');
            $table->timestamp('sync_completed_at')->nullable();
            $table->integer('templates_fetched')->default(0);
            $table->integer('templates_synced')->default(0);
            $table->integer('templates_updated')->default(0);
            $table->integer('templates_failed')->default(0);
            $table->text('error_message')->nullable();
            $table->string('status'); // 'running', 'completed', 'failed'
            $table->json('raw_response')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'provider_account_id']);
            $table->index(['status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meta_template_sync_logs');
        Schema::dropIfExists('meta_whatsapp_templates');
    }
};
