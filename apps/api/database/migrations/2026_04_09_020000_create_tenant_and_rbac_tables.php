<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 255);
            $table->string('slug', 100)->unique();
            $table->string('status', 20)->default('active');
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspension_reason', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('status');
        });

        Schema::create('tenant_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('timezone', 50)->default('UTC');
            $table->string('locale', 10)->default('en');
            $table->string('date_format', 20)->default('YYYY-MM-DD');
            $table->string('branding_company_name', 255)->nullable();
            $table->string('branding_logo_url', 500)->nullable();
            $table->string('default_webhook_url', 500)->nullable();
            $table->string('alert_email', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug', 50)->unique();
            $table->string('name', 100);
            $table->string('description', 500)->nullable();
            $table->boolean('is_platform_role')->default(false);
            $table->smallInteger('hierarchy_level')->unique();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug', 100)->unique();
            $table->string('name', 150);
            $table->string('module', 50);
            $table->string('description', 500)->nullable();
            $table->boolean('is_plan_gated')->default(false);
            $table->timestamp('created_at')->useCurrent();
            $table->index('module');
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->uuid('role_id');
            $table->uuid('permission_id');
            $table->primary(['role_id', 'permission_id']);
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
        });

        Schema::create('memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id')->nullable();
            $table->uuid('role_id');
            $table->string('status', 20)->default('active');
            $table->uuid('invited_by')->nullable();
            $table->string('invitation_token', 100)->nullable();
            $table->timestamp('invitation_expires_at')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id']);
            $table->index('user_id');
            $table->index('invitation_token');

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('role_id')->references('id')->on('roles')->restrictOnDelete();
            $table->foreign('invited_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('actor_id')->nullable();
            $table->string('actor_type', 20)->default('user');
            $table->string('action', 100);
            $table->string('resource_type', 50);
            $table->uuid('resource_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->uuid('correlation_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['actor_id', 'created_at']);
            $table->index('action');
            $table->index(['resource_type', 'resource_id']);

            $table->foreign('tenant_id')->references('id')->on('tenants')->nullOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('memberships');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('tenant_settings');
        Schema::dropIfExists('tenants');
    }
};
