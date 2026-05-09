<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_sessions', function (Blueprint $table) {
            $table->string('recording_url', 500)->nullable()->after('routing_confidence');
            $table->unsignedInteger('recording_duration')->nullable()->after('recording_url');
            $table->json('recording_tags')->nullable()->after('recording_duration');
            $table->text('recording_notes')->nullable()->after('recording_tags');
        });
    }

    public function down(): void
    {
        Schema::table('call_sessions', function (Blueprint $table) {
            $table->dropColumn([
                'recording_url',
                'recording_duration',
                'recording_tags',
                'recording_notes',
            ]);
        });
    }
};
