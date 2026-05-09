<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('graph_bookings', function (Blueprint $table) {
            $table->string('status', 20)->default('confirmed')->after('end_at');
            $table->timestamp('canceled_at')->nullable()->after('status');

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'attendee_email', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('graph_bookings', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'status']);
            $table->dropIndex(['tenant_id', 'attendee_email', 'status']);
            $table->dropColumn(['status', 'canceled_at']);
        });
    }
};

