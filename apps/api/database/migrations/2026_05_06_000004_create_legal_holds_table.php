<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_holds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('scope_type', 30);
            $table->uuid('scope_id')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('reason', 255)->nullable();
            $table->boolean('active')->default(true);
            $table->uuid('created_by')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['tenant_id', 'active']);
            $table->index(['tenant_id', 'scope_type', 'scope_id']);
            $table->index(['tenant_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_holds');
    }
};

