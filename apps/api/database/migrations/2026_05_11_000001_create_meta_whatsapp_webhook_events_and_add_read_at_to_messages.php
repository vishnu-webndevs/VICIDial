<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('meta_whatsapp_webhook_events')) {
            Schema::create('meta_whatsapp_webhook_events', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->nullable();
                $table->uuid('provider_account_id')->nullable();
                $table->string('status', 20)->default('pending');
                $table->string('event_type', 50)->default('meta_whatsapp.webhook');
                $table->json('headers')->nullable();
                $table->json('payload');
                $table->integer('processed_status_count')->default(0);
                $table->integer('processed_message_count')->default(0);
                $table->timestamp('processed_at')->nullable();
                $table->string('error_message', 1000)->nullable();
                $table->timestamps();

                $table->index(['status', 'created_at'], 'mwa_events_status_created_at_idx');
                $table->index(['tenant_id', 'created_at'], 'mwa_events_tenant_created_at_idx');
                $table->index(['provider_account_id', 'created_at'], 'mwa_events_provider_created_at_idx');
            });
        } else {
            try {
                Schema::table('meta_whatsapp_webhook_events', function (Blueprint $table) {
                    $table->index(['status', 'created_at'], 'mwa_events_status_created_at_idx');
                    $table->index(['tenant_id', 'created_at'], 'mwa_events_tenant_created_at_idx');
                    $table->index(['provider_account_id', 'created_at'], 'mwa_events_provider_created_at_idx');
                });
            } catch (\Throwable) {
            }
        }

        if (!Schema::hasColumn('messages', 'read_at')) {
            Schema::table('messages', function (Blueprint $table) {
                $table->timestamp('read_at')->nullable()->after('delivered_at');
            });
        }

        try {
            $existing = DB::select("SHOW INDEX FROM `messages` WHERE Key_name = 'messages_tenant_id_read_at_index'");
            if ($existing === []) {
                Schema::table('messages', function (Blueprint $table) {
                    $table->index(['tenant_id', 'read_at']);
                });
            }
        } catch (\Throwable) {
        }
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'read_at']);
            $table->dropColumn(['read_at']);
        });

        Schema::dropIfExists('meta_whatsapp_webhook_events');
    }
};
