<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('channel', 20);
            $table->string('key', 80);
            $table->string('name', 150);
            $table->text('body');
            $table->boolean('is_active')->default(true);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['tenant_id', 'channel', 'key']);
            $table->index(['tenant_id', 'channel', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_templates');
    }
};

