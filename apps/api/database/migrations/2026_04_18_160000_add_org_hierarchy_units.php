<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('org_units', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('parent_id')->nullable();
            $table->string('type', 20); // agency | team
            $table->string('name', 255);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'type']);
            $table->index(['tenant_id', 'parent_id']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('parent_id')->references('id')->on('org_units')->nullOnDelete();
        });

        Schema::table('memberships', function (Blueprint $table) {
            $table->uuid('agency_unit_id')->nullable()->after('role_id');
            $table->uuid('team_unit_id')->nullable()->after('agency_unit_id');

            $table->index(['tenant_id', 'agency_unit_id']);
            $table->index(['tenant_id', 'team_unit_id']);
            $table->foreign('agency_unit_id')->references('id')->on('org_units')->nullOnDelete();
            $table->foreign('team_unit_id')->references('id')->on('org_units')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->dropForeign(['agency_unit_id']);
            $table->dropForeign(['team_unit_id']);
            $table->dropIndex(['tenant_id', 'agency_unit_id']);
            $table->dropIndex(['tenant_id', 'team_unit_id']);
            $table->dropColumn(['agency_unit_id', 'team_unit_id']);
        });

        Schema::dropIfExists('org_units');
    }
};
