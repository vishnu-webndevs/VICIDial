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
        Schema::table('meta_whatsapp_templates', function (Blueprint $table) {
            // Drop the old global unique index on meta_template_id
            $table->dropUnique('meta_whatsapp_templates_meta_template_id_unique');
            
            // Add a new unique index scoped to tenant_id and meta_template_id
            $table->unique(['tenant_id', 'meta_template_id'], 'meta_whatsapp_templates_tenant_template_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('meta_whatsapp_templates', function (Blueprint $table) {
            // Drop the composite unique index
            $table->dropUnique('meta_whatsapp_templates_tenant_template_unique');
            
            // Restore the old global unique index
            $table->unique('meta_template_id', 'meta_whatsapp_templates_meta_template_id_unique');
        });
    }
};
