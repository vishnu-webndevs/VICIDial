<?php

namespace App\Console\Commands;

use App\Models\Message;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DebugMetaWhatsapp extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'whatsapp:debug-webhooks {provider_message_id? : The Meta message ID to inspect} {--limit=10 : Number of recent webhooks to show}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Debug Meta WhatsApp webhook deliveries and error codes';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $providerMessageId = $this->argument('provider_message_id');

        if ($providerMessageId) {
            $this->inspectMessage($providerMessageId);
        } else {
            $this->listRecentWebhooks((int) $this->option('limit'));
        }
    }

    private function inspectMessage(string $providerMessageId): void
    {
        $this->info("Inspecting Meta WhatsApp Message ID: {$providerMessageId}");

        $message = Message::query()
            ->where('provider_message_id', $providerMessageId)
            ->first();

        if (! $message) {
            $this->error("Message not found in database.");
            return;
        }

        $this->table(
            ['ID', 'Tenant ID', 'Direction', 'Status', 'Sent At', 'Delivered At', 'Read At'],
            [[
                $message->id,
                $message->tenant_id,
                $message->direction,
                $message->status,
                $message->sent_at,
                $message->delivered_at,
                $message->read_at,
            ]]
        );

        $metadata = (array) ($message->metadata ?? []);

        if (isset($metadata['error'])) {
            $this->newLine();
            $this->error("Error: " . $metadata['error']);
        }

        if (isset($metadata['meta_errors'])) {
            $this->newLine();
            $this->warn("Meta API Errors:");
            $this->line(json_encode($metadata['meta_errors'], JSON_PRETTY_PRINT));
        }

        if (isset($metadata['meta_conversation'])) {
            $this->newLine();
            $this->info("Conversation Data:");
            $this->line(json_encode($metadata['meta_conversation'], JSON_PRETTY_PRINT));
        }

        if (isset($metadata['meta_pricing'])) {
            $this->newLine();
            $this->info("Pricing Data:");
            $this->line(json_encode($metadata['meta_pricing'], JSON_PRETTY_PRINT));
        }
    }

    private function listRecentWebhooks(int $limit): void
    {
        $this->info("Listing {$limit} most recent failed/delivered Meta webhook updates from messages:");

        $messages = Message::query()
            ->whereNotNull('provider_message_id')
            ->where('provider_message_id', 'like', 'wamid.%')
            ->whereNotNull('metadata->status_callback')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        if ($messages->isEmpty()) {
            $this->warn("No Meta messages with webhook payloads found.");
            return;
        }

        $rows = [];
        foreach ($messages as $message) {
            $metadata = (array) ($message->metadata ?? []);
            
            $rows[] = [
                'Local ID' => substr($message->id, 0, 8) . '...',
                'Meta ID' => substr($message->provider_message_id, 0, 16) . '...',
                'Status' => $message->status,
                'Error Code' => isset($metadata['meta_errors']) ? data_get($metadata['meta_errors'], '0.code', 'N/A') : 'None',
                'Updated At' => $message->updated_at->diffForHumans(),
            ];
        }

        $this->table(['Local ID', 'Meta ID', 'Status', 'Error Code', 'Updated At'], $rows);
        $this->info("Run 'php artisan whatsapp:debug-webhooks <Meta ID>' to see full debug data.");
    }
}
