<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messaging_opt_outs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('phone', 30);
            $table->string('channel', 20);
            $table->boolean('opted_out')->default(true);
            $table->string('source', 50)->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('last_changed_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'phone', 'channel']);
            $table->index(['tenant_id', 'channel', 'opted_out']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messaging_opt_outs');
    }
};

