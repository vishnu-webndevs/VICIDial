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
        Schema::table('message_templates', function (Blueprint $table) {
            $table->uuid('meta_template_id')->nullable()->after('body');
            $table->boolean('is_meta_approved')->default(false)->after('meta_template_id');
            
            $table->foreign('meta_template_id')
                ->references('id')
                ->on('meta_whatsapp_templates')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('message_templates', function (Blueprint $table) {
            $table->dropForeign(['meta_template_id']);
            $table->dropColumn(['meta_template_id', 'is_meta_approved']);
        });
    }
};
