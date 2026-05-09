<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
            $table->decimal('price_monthly', 10, 2)->default(0)->after('description');
            $table->decimal('price_yearly', 10, 2)->default(0)->after('price_monthly');
            $table->boolean('is_public')->default(false)->after('is_active');
            $table->unsignedInteger('sort_order')->default(0)->after('is_public');
        });

        Schema::create('plan_features', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('plan_id');
            $table->string('key', 120);
            $table->string('type', 20)->default('limit');
            $table->string('value', 120)->default('0');
            $table->string('label', 160)->nullable();
            $table->timestamps();

            $table->foreign('plan_id')->references('id')->on('plans')->cascadeOnDelete();
            $table->unique(['plan_id', 'key']);
            $table->index(['plan_id', 'type']);
        });

        Schema::create('tenant_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('plan_id');
            $table->string('billing_cycle', 20)->default('monthly');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('plan_id')->references('id')->on('plans')->restrictOnDelete();
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'started_at']);
        });

        Schema::create('plan_usage', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('feature_key', 120);
            $table->unsignedBigInteger('current_value')->default(0);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'feature_key']);
            $table->index(['tenant_id', 'last_synced_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_usage');
        Schema::dropIfExists('tenant_plans');
        Schema::dropIfExists('plan_features');

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn([
                'description',
                'price_monthly',
                'price_yearly',
                'is_public',
                'sort_order',
            ]);
        });
    }
};
