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
            $table->string('header_type')->nullable()->after('language'); // NONE, TEXT, IMAGE, VIDEO, DOCUMENT
            $table->text('header_content')->nullable()->after('header_type');
            $table->text('body')->nullable()->after('header_content');
            $table->text('footer')->nullable()->after('body');
            $table->json('buttons')->nullable()->after('footer');
            $table->string('rejection_reason')->nullable()->after('status');
            $table->foreignUuid('created_by')->nullable()->after('rejection_reason')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('meta_whatsapp_templates', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropColumn([
                'header_type',
                'header_content',
                'body',
                'footer',
                'buttons',
                'rejection_reason',
                'created_by',
            ]);
        });
    }
};
