<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_phone_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id');
            $table->uuid('provider_phone_number_id');
            $table->string('status', 20)->default('active');
            $table->uuid('assigned_by')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('provider_phone_number_id')->references('id')->on('provider_phone_numbers')->cascadeOnDelete();
            $table->foreign('assigned_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['tenant_id', 'user_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'provider_phone_number_id']);
        });

        Schema::create('campaign_agent_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('campaign_id');
            $table->uuid('user_id');
            $table->uuid('provider_phone_number_id');
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('campaign_id')->references('id')->on('campaigns')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('provider_phone_number_id')->references('id')->on('provider_phone_numbers')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['tenant_id', 'campaign_id', 'user_id']);
            $table->index(['tenant_id', 'campaign_id']);
            $table->index(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_agent_assignments');
        Schema::dropIfExists('agent_phone_assignments');
    }
};
