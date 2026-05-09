<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug', 50)->unique();
            $table->string('name', 100);
            $table->string('billing_cycle', 20)->default('monthly');
            $table->unsignedInteger('monthly_price_cents')->default(0);
            $table->unsignedInteger('yearly_price_cents')->default(0);
            $table->unsignedInteger('trial_days')->default(0);
            $table->unsignedInteger('api_quota_monthly')->default(0);
            $table->unsignedInteger('call_minutes_monthly')->default(0);
            $table->unsignedInteger('webhook_events_monthly')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('stripe_product_id', 100)->nullable();
            $table->string('stripe_price_monthly_id', 100)->nullable();
            $table->string('stripe_price_yearly_id', 100)->nullable();
            $table->timestamps();
            $table->index('is_active');
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('plan_id');
            $table->string('status', 20)->default('trialing');
            $table->string('billing_cycle', 20)->default('monthly');
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->string('stripe_customer_id', 100)->nullable();
            $table->string('stripe_subscription_id', 100)->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('plan_id')->references('id')->on('plans')->restrictOnDelete();
            $table->index(['tenant_id', 'status']);
            $table->index('stripe_subscription_id');
        });

        Schema::create('usage_meters', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('subscription_id')->nullable();
            $table->string('meter_type', 50);
            $table->unsignedBigInteger('consumed_units')->default(0);
            $table->unsignedBigInteger('limit_units')->default(0);
            // Keep nullable for MariaDB strict mode compatibility (avoids implicit zero timestamp defaults).
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('subscription_id')->references('id')->on('subscriptions')->nullOnDelete();
            $table->index(['tenant_id', 'meter_type', 'period_start']);
        });

        Schema::create('usage_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('subscription_id')->nullable();
            $table->string('meter_type', 50);
            $table->unsignedInteger('quantity')->default(1);
            $table->string('source_type', 50)->nullable();
            $table->uuid('source_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('subscription_id')->references('id')->on('subscriptions')->nullOnDelete();
            $table->index(['tenant_id', 'meter_type', 'occurred_at']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('subscription_id')->nullable();
            $table->string('invoice_number', 50)->nullable();
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('subtotal_cents')->default(0);
            $table->unsignedInteger('tax_cents')->default(0);
            $table->unsignedInteger('total_cents')->default(0);
            $table->string('currency', 10)->default('USD');
            $table->string('stripe_invoice_id', 100)->nullable();
            $table->string('hosted_invoice_url', 500)->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('subscription_id')->references('id')->on('subscriptions')->nullOnDelete();
            $table->index(['tenant_id', 'created_at']);
            $table->index('stripe_invoice_id');
        });

        Schema::create('payment_methods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('stripe_payment_method_id', 100)->unique();
            $table->string('card_brand', 30)->nullable();
            $table->string('card_last_four', 4)->nullable();
            $table->unsignedTinyInteger('card_exp_month')->nullable();
            $table->unsignedSmallInteger('card_exp_year')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('usage_events');
        Schema::dropIfExists('usage_meters');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
    }
};
