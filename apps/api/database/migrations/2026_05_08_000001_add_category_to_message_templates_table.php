<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_templates', function (Blueprint $table) {
            if (! Schema::hasColumn('message_templates', 'category')) {
                $table->string('category', 50)->nullable()->after('channel');
                $table->index(['tenant_id', 'category']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('message_templates', function (Blueprint $table) {
            if (Schema::hasColumn('message_templates', 'category')) {
                $table->dropIndex(['tenant_id', 'category']);
                $table->dropColumn('category');
            }
        });
    }
};

