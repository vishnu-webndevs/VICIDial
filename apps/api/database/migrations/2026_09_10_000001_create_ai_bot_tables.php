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
        if (!Schema::hasTable('tenant_ai_settings')) {
            Schema::create('tenant_ai_settings', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->unique();
                $table->string('provider', 30)->default('gemini');
                $table->text('api_key')->nullable(); // Encrypted string
                $table->boolean('enabled')->default(true);
                $table->string('default_model', 50)->default('gemini-1.5-flash');
                $table->timestamps();

                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('ai_bot_agents')) {
            Schema::create('ai_bot_agents', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id');
                $table->string('name', 100);
                $table->string('description', 255)->nullable();
                $table->text('system_instructions')->nullable();
                $table->json('knowledge_base')->nullable(); // Q&A array / FAQ context
                $table->text('fallback_message')->nullable();
                $table->boolean('strict_mode')->default(true);
                $table->unsignedInteger('human_delay_seconds')->default(3);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
                $table->index(['tenant_id', 'is_active']);
            });
        }

        if (!Schema::hasTable('ai_bot_interactive_flows')) {
            Schema::create('ai_bot_interactive_flows', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('ai_bot_agent_id');
                $table->string('trigger_keyword', 100); // e.g. "Interested" or "2BHK"
                $table->string('response_type', 30)->default('button_list'); // button_list | text
                $table->text('question_text');
                $table->json('options')->nullable(); // ["2BHK", "3BHK", "4BHK"]
                $table->timestamps();

                $table->foreign('ai_bot_agent_id')->references('id')->on('ai_bot_agents')->cascadeOnDelete();
            });
        }

        if (!Schema::hasColumn('campaigns', 'ai_bot_agent_id')) {
            Schema::table('campaigns', function (Blueprint $table) {
                $table->uuid('ai_bot_agent_id')->nullable();
            });
        }

        if (!Schema::hasColumn('message_threads', 'ai_bot_agent_id')) {
            Schema::table('message_threads', function (Blueprint $table) {
                $table->uuid('ai_bot_agent_id')->nullable()->after('assigned_user_id');
                $table->string('bot_status', 20)->default('active')->after('ai_bot_agent_id'); // active | paused | human_assigned
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('message_threads', 'ai_bot_agent_id')) {
            Schema::table('message_threads', function (Blueprint $table) {
                $table->dropColumn(['ai_bot_agent_id', 'bot_status']);
            });
        }

        if (Schema::hasColumn('campaigns', 'ai_bot_agent_id')) {
            Schema::table('campaigns', function (Blueprint $table) {
                $table->dropColumn('ai_bot_agent_id');
            });
        }

        Schema::dropIfExists('ai_bot_interactive_flows');
        Schema::dropIfExists('ai_bot_agents');
        Schema::dropIfExists('tenant_ai_settings');
    }
};
