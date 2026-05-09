<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('display_name', 255);
            $table->string('company', 255)->nullable();
            $table->string('role', 80)->nullable();
            $table->json('tags')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'display_name']);
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('contact_phones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('contact_id');
            $table->string('e164', 30);
            $table->string('label', 50)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('contact_id')->references('id')->on('contacts')->cascadeOnDelete();
            $table->unique(['tenant_id', 'e164']);
            $table->index(['contact_id', 'is_primary']);
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name', 255);
            $table->string('site_address', 255)->nullable();
            $table->string('status', 40)->default('active');
            $table->string('priority', 20)->default('normal');
            $table->uuid('owner_contact_id')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('owner_contact_id')->references('id')->on('contacts')->nullOnDelete();
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'priority']);
        });

        Schema::create('contact_project_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('contact_id');
            $table->uuid('project_id');
            $table->string('relationship_type', 50)->default('stakeholder');
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('contact_id')->references('id')->on('contacts')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->unique(['contact_id', 'project_id', 'relationship_type'], 'contact_project_links_unique');
            $table->index(['tenant_id', 'project_id']);
        });

        Schema::create('project_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('project_id');
            $table->uuid('engineer_id');
            $table->string('role', 20)->default('primary');
            $table->timestamp('active_from')->nullable();
            $table->timestamp('active_to')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('engineer_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['tenant_id', 'project_id']);
            $table->index(['tenant_id', 'engineer_id']);
        });

        Schema::create('extensions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('extension', 10);
            $table->string('target_type', 30);
            $table->uuid('target_id');
            $table->boolean('is_reserved')->default(false);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'extension']);
            $table->index(['tenant_id', 'target_type', 'target_id']);
        });

        Schema::create('ring_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name', 150);
            $table->string('strategy', 30)->default('simultaneous');
            $table->unsignedInteger('ring_timeout_seconds')->default(20);
            $table->unsignedInteger('max_queue_seconds')->default(120);
            $table->unsignedInteger('max_retries')->default(1);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'active']);
        });

        Schema::create('ring_group_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('ring_group_id');
            $table->string('target_type', 30);
            $table->uuid('target_id')->nullable();
            $table->string('external_number', 30)->nullable();
            $table->unsignedInteger('priority')->default(100);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('ring_group_id')->references('id')->on('ring_groups')->cascadeOnDelete();
            $table->index(['ring_group_id', 'priority']);
        });

        Schema::create('voicemail_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('call_session_id')->nullable();
            $table->uuid('contact_id')->nullable();
            $table->uuid('project_id')->nullable();
            $table->string('from_number', 30)->nullable();
            $table->string('to_number', 30)->nullable();
            $table->string('storage_url', 500)->nullable();
            $table->text('transcript')->nullable();
            $table->string('status', 30)->default('captured');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('call_session_id')->references('id')->on('call_sessions')->nullOnDelete();
            $table->foreign('contact_id')->references('id')->on('contacts')->nullOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->nullOnDelete();
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('message_threads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('channel', 20);
            $table->string('counterparty_number', 30)->nullable();
            $table->uuid('contact_id')->nullable();
            $table->uuid('project_id')->nullable();
            $table->uuid('assigned_user_id')->nullable();
            $table->string('status', 20)->default('open');
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('contact_id')->references('id')->on('contacts')->nullOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->nullOnDelete();
            $table->foreign('assigned_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['tenant_id', 'channel', 'status']);
            $table->index(['tenant_id', 'counterparty_number']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('thread_id');
            $table->string('direction', 10);
            $table->string('status', 30)->default('queued');
            $table->text('body')->nullable();
            $table->json('media')->nullable();
            $table->uuid('sent_by_user_id')->nullable();
            $table->string('provider_message_id', 120)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('thread_id')->references('id')->on('message_threads')->cascadeOnDelete();
            $table->foreign('sent_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['thread_id', 'created_at']);
            $table->index(['tenant_id', 'provider_message_id']);
        });

        Schema::table('call_sessions', function (Blueprint $table) {
            $table->uuid('contact_id')->nullable()->after('provider_account_id');
            $table->uuid('project_id')->nullable()->after('contact_id');
            $table->string('runtime_state', 40)->default('initiated')->after('status');
            $table->string('routed_to', 120)->nullable()->after('failure_reason');
            $table->decimal('routing_confidence', 5, 2)->nullable()->after('routed_to');
        });

        Schema::create('call_legs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('call_session_id');
            $table->string('from_number', 30)->nullable();
            $table->string('to_number', 30)->nullable();
            $table->string('status', 30)->default('initiated');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->uuid('bridged_to_leg_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('call_session_id')->references('id')->on('call_sessions')->cascadeOnDelete();
            $table->index(['call_session_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_legs');

        Schema::table('call_sessions', function (Blueprint $table) {
            $table->dropColumn(['contact_id', 'project_id', 'runtime_state', 'routed_to', 'routing_confidence']);
        });

        Schema::dropIfExists('messages');
        Schema::dropIfExists('message_threads');
        Schema::dropIfExists('voicemail_messages');
        Schema::dropIfExists('ring_group_members');
        Schema::dropIfExists('ring_groups');
        Schema::dropIfExists('extensions');
        Schema::dropIfExists('project_assignments');
        Schema::dropIfExists('contact_project_links');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('contact_phones');
        Schema::dropIfExists('contacts');
    }
};
