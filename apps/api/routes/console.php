<?php

use App\Jobs\RunCampaignDialerJob;
use App\Models\CallSession;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\Notification;
use App\Models\LegalHold;
use App\Models\RetentionPolicy;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\VoicemailMessage;
use App\Models\DataSubjectRequest;
use App\Models\Lead;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('dialer-incidents:prune', function () {
    $retentionDays = (int) config('integrations.dialer_incidents.retention_days', 90);
    $cutoff = now()->subDays($retentionDays);
    $deleted = DB::table('dialer_loop_incidents')
        ->where('occurred_at', '<', $cutoff)
        ->delete();

    $this->info("Pruned {$deleted} dialer loop incidents older than {$retentionDays} days.");
})->purpose('Prune old dialer loop incident logs according to retention policy');

Artisan::command('retention:enforce {--tenant=} {--execute}', function () {
    $execute = (bool) $this->option('execute');
    $tenantFilter = (string) ($this->option('tenant') ?? '');

    $policies = RetentionPolicy::query()
        ->when($tenantFilter !== '', fn ($q) => $q->where('tenant_id', $tenantFilter))
        ->get();

    if ($policies->isEmpty()) {
        $this->info('No retention policies found.');
        return;
    }

    foreach ($policies as $policy) {
        $days = (int) ($policy->retention_days ?? 90);
        if ($days <= 0) {
            continue;
        }

        $cutoff = now()->subDays($days);
        $tenantId = (string) $policy->tenant_id;
        $redact = (bool) ($policy->pii_redaction_enabled ?? true);

        $heldPhones = LegalHold::query()
            ->where('tenant_id', $tenantId)
            ->where('active', true)
            ->where('scope_type', 'phone')
            ->whereNotNull('phone')
            ->pluck('phone')
            ->all();

        $messageQuery = DB::table('messages')
            ->where('messages.tenant_id', $tenantId)
            ->where('messages.created_at', '<', $cutoff);
        if ($heldPhones !== []) {
            $messageQuery = $messageQuery
                ->join('message_threads', 'message_threads.id', '=', 'messages.thread_id')
                ->whereNotIn('message_threads.counterparty_number', $heldPhones);
        }

        $vmQuery = VoicemailMessage::query()
            ->where('tenant_id', $tenantId)
            ->where('created_at', '<', $cutoff)
            ->when($heldPhones !== [], fn ($q) => $q->whereNotIn('from_number', $heldPhones)->whereNotIn('to_number', $heldPhones));

        $callQuery = CallSession::query()
            ->where('tenant_id', $tenantId)
            ->where('created_at', '<', $cutoff)
            ->when($heldPhones !== [], fn ($q) => $q->whereNotIn('from_number', $heldPhones)->whereNotIn('to_number', $heldPhones));

        $messageCount = (clone $messageQuery)->count();
        $vmCount = (clone $vmQuery)->count();
        $callCount = (clone $callQuery)->count();

        if (! $execute) {
            $this->info("Tenant {$tenantId}: would process messages={$messageCount}, voicemails={$vmCount}, calls={$callCount} (cutoff {$cutoff->toDateString()})");
            continue;
        }

        if ($redact) {
            $redactedMessages = $messageQuery->update([
                'body' => '[redacted]',
                'media' => null,
                'metadata' => null,
                'updated_at' => now(),
            ]);
            $redactedVoicemails = $vmQuery->update([
                'transcript' => null,
                'metadata' => null,
                'updated_at' => now(),
            ]);
            $redactedCalls = $callQuery->update([
                'recording_url' => null,
                'recording_notes' => null,
                'recording_tags' => null,
                'metadata' => null,
                'updated_at' => now(),
            ]);

            $this->info("Tenant {$tenantId}: redacted messages={$redactedMessages}, voicemails={$redactedVoicemails}, calls={$redactedCalls}");
        } else {
            $deletedMessages = $messageQuery->delete();
            $deletedVoicemails = $vmQuery->delete();
            $deletedCalls = $callQuery->delete();

            $this->info("Tenant {$tenantId}: deleted messages={$deletedMessages}, voicemails={$deletedVoicemails}, calls={$deletedCalls}");
        }
    }
})->purpose('Enforce tenant retention policies (redact or delete data older than retention_days)');

Artisan::command('inbox:sla-evaluate {--tenant=} {--execute}', function () {
    $execute = (bool) $this->option('execute');
    $tenantFilter = (string) ($this->option('tenant') ?? '');
    $now = now();

    $tenantIds = MessageThread::query()
        ->select('tenant_id')
        ->distinct()
        ->when($tenantFilter !== '', fn ($q) => $q->where('tenant_id', $tenantFilter))
        ->pluck('tenant_id')
        ->all();

    foreach ($tenantIds as $tenantId) {
        $setting = TenantSetting::query()->where('tenant_id', $tenantId)->first();
        $metadata = (array) ($setting?->metadata ?? []);
        $policy = array_merge([
            'enabled' => true,
            'first_response_minutes' => 60,
            'resolution_minutes' => 1440,
        ], (array) ($metadata['inbox_sla'] ?? []));

        if (($policy['enabled'] ?? true) !== true) {
            continue;
        }

        $openQuery = MessageThread::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['open', 'pending']);

        $firstResponseBreach = (clone $openQuery)
            ->whereNotNull('first_inbound_at')
            ->whereNull('first_outbound_at')
            ->whereNull('sla_first_response_breached_at')
            ->whereNotNull('first_response_due_at')
            ->where('first_response_due_at', '<', $now)
            ->get();

        $resolutionBreach = (clone $openQuery)
            ->whereNotNull('first_inbound_at')
            ->whereNull('sla_resolution_breached_at')
            ->whereNotNull('resolution_due_at')
            ->where('resolution_due_at', '<', $now)
            ->get();

        if (! $execute) {
            $this->info("Tenant {$tenantId}: first_response_breaches={$firstResponseBreach->count()}, resolution_breaches={$resolutionBreach->count()}");
            continue;
        }

        foreach ($firstResponseBreach as $thread) {
            $thread->sla_first_response_breached_at = $now;
            $thread->save();

            if ($thread->assigned_user_id) {
                Notification::query()->create([
                    'tenant_id' => $tenantId,
                    'user_id' => $thread->assigned_user_id,
                    'type' => 'inbox.sla.first_response_breached',
                    'title' => 'Inbox SLA breached: first response',
                    'message' => "Thread {$thread->id} missed the first response SLA.",
                    'metadata' => [
                        'thread_id' => $thread->id,
                        'channel' => $thread->channel,
                        'counterparty_number' => $thread->counterparty_number,
                        'first_response_due_at' => $thread->first_response_due_at?->toISOString(),
                    ],
                ]);
            }
        }

        foreach ($resolutionBreach as $thread) {
            $thread->sla_resolution_breached_at = $now;
            $thread->save();

            if ($thread->assigned_user_id) {
                Notification::query()->create([
                    'tenant_id' => $tenantId,
                    'user_id' => $thread->assigned_user_id,
                    'type' => 'inbox.sla.resolution_breached',
                    'title' => 'Inbox SLA breached: resolution',
                    'message' => "Thread {$thread->id} missed the resolution SLA.",
                    'metadata' => [
                        'thread_id' => $thread->id,
                        'channel' => $thread->channel,
                        'counterparty_number' => $thread->counterparty_number,
                        'resolution_due_at' => $thread->resolution_due_at?->toISOString(),
                    ],
                ]);
            }
        }
    }
})->purpose('Evaluate inbox SLA deadlines and create notifications when breached');

Artisan::command('dsr:process {--tenant=} {--execute}', function () {
    $execute = (bool) $this->option('execute');
    $tenantFilter = (string) ($this->option('tenant') ?? '');

    $requests = DataSubjectRequest::query()
        ->whereIn('status', ['queued', 'approved'])
        ->when($tenantFilter !== '', fn ($q) => $q->where('tenant_id', $tenantFilter))
        ->oldest('created_at')
        ->limit(50)
        ->get();

    foreach ($requests as $dsr) {
        $tenantId = (string) $dsr->tenant_id;
        $subjectType = (string) $dsr->subject_type;
        $subjectValue = (string) $dsr->subject_value;

        if (! $execute) {
            $this->info("Would process DSR {$dsr->id} ({$dsr->request_type}) for {$subjectType}={$subjectValue}");
            continue;
        }

        $dsr->status = 'processing';
        $dsr->save();

        $held = false;
        if ($subjectType === 'phone') {
            $held = LegalHold::query()
                ->where('tenant_id', $tenantId)
                ->where('active', true)
                ->where('scope_type', 'phone')
                ->where('phone', $subjectValue)
                ->exists();
        }

        if ($dsr->request_type === 'export') {
            $payload = [
                'tenant_id' => $tenantId,
                'subject' => ['type' => $subjectType, 'value' => $subjectValue],
                'generated_at' => now()->toISOString(),
                'leads' => Lead::query()
                    ->where('tenant_id', $tenantId)
                    ->when($subjectType === 'phone', fn ($q) => $q->where('phone', $subjectValue))
                    ->when($subjectType === 'email', fn ($q) => $q->where('email', $subjectValue))
                    ->limit(200)
                    ->get()
                    ->toArray(),
                'calls' => CallSession::query()
                    ->where('tenant_id', $tenantId)
                    ->when($subjectType === 'phone', fn ($q) => $q->where('to_number', $subjectValue)->orWhere('from_number', $subjectValue))
                    ->limit(500)
                    ->get()
                    ->toArray(),
                'messages' => DB::table('messages')
                    ->join('message_threads', 'message_threads.id', '=', 'messages.thread_id')
                    ->where('messages.tenant_id', $tenantId)
                    ->when($subjectType === 'phone', fn ($q) => $q->where('message_threads.counterparty_number', $subjectValue))
                    ->limit(1000)
                    ->select('messages.*', 'message_threads.channel as thread_channel', 'message_threads.counterparty_number')
                    ->get()
                    ->toArray(),
                'voicemails' => VoicemailMessage::query()
                    ->where('tenant_id', $tenantId)
                    ->when($subjectType === 'phone', fn ($q) => $q->where('from_number', $subjectValue)->orWhere('to_number', $subjectValue))
                    ->limit(200)
                    ->get()
                    ->toArray(),
            ];

            $path = 'dsr/'.$tenantId.'/export_'.$dsr->id.'.json';
            Storage::disk('local')->put($path, json_encode($payload, JSON_PRETTY_PRINT));
            $dsr->status = 'completed';
            $dsr->result_path = $path;
            $dsr->processed_at = now();
            $dsr->save();
            continue;
        }

        if ($dsr->request_type === 'erase') {
            if ($held) {
                $dsr->status = 'blocked_legal_hold';
                $dsr->metadata = array_merge((array) ($dsr->metadata ?? []), ['blocked' => 'legal_hold']);
                $dsr->processed_at = now();
                $dsr->save();
                continue;
            }

            $leadQuery = Lead::query()->where('tenant_id', $tenantId);
            if ($subjectType === 'phone') {
                $leadQuery->where('phone', $subjectValue);
            } else {
                $leadQuery->where('email', $subjectValue);
            }
            $leadsRedacted = $leadQuery->update([
                'full_name' => '[redacted]',
                'email' => null,
                'company' => null,
                'notes' => [],
                'tags' => [],
                'updated_at' => now(),
            ]);

            $messagesRedacted = 0;
            if ($subjectType === 'phone') {
                $messagesRedacted = DB::table('messages')
                    ->join('message_threads', 'message_threads.id', '=', 'messages.thread_id')
                    ->where('messages.tenant_id', $tenantId)
                    ->where('message_threads.counterparty_number', $subjectValue)
                    ->update([
                        'body' => '[redacted]',
                        'media' => null,
                        'metadata' => null,
                        'updated_at' => now(),
                    ]);
            }

            $callsRedacted = CallSession::query()
                ->where('tenant_id', $tenantId)
                ->when($subjectType === 'phone', fn ($q) => $q->where('to_number', $subjectValue)->orWhere('from_number', $subjectValue))
                ->update([
                    'recording_url' => null,
                    'recording_notes' => null,
                    'recording_tags' => null,
                    'metadata' => null,
                    'updated_at' => now(),
                ]);

            $dsr->status = 'completed';
            $dsr->processed_at = now();
            $dsr->metadata = array_merge((array) ($dsr->metadata ?? []), [
                'leads_redacted' => $leadsRedacted,
                'messages_redacted' => $messagesRedacted,
                'calls_redacted' => $callsRedacted,
            ]);
            $dsr->save();
            continue;
        }

        $dsr->status = 'failed';
        $dsr->processed_at = now();
        $dsr->metadata = array_merge((array) ($dsr->metadata ?? []), ['error' => 'unknown_request_type']);
        $dsr->save();
    }
})->purpose('Process data subject requests (export/erase) for a tenant');

Artisan::command('account:purge-expired {--execute}', function () {
    $execute = (bool) $this->option('execute');
    $now = now();

    $query = User::withTrashed()
        ->whereNotNull('deleted_at')
        ->whereNotNull('deletion_scheduled_at')
        ->where('deletion_scheduled_at', '<', $now);

    $count = (clone $query)->count();

    if (! $execute) {
        $this->info("Would purge {$count} expired account(s).");
        return;
    }

    $users = $query->get();
    if ($users->isEmpty()) {
        $this->info('No expired accounts to purge.');
        return;
    }

    foreach ($users as $user) {
        DB::transaction(function () use ($user) {
            DB::table('personal_access_tokens')
                ->where('tokenable_type', User::class)
                ->where('tokenable_id', $user->id)
                ->delete();

            DB::table('sessions')
                ->where('user_id', $user->id)
                ->delete();

            $tenantIds = Membership::query()
                ->where('user_id', $user->id)
                ->whereHas('role', fn ($q) => $q->where('slug', 'company_owner'))
                ->pluck('tenant_id')
                ->all();

            if ($tenantIds !== []) {
                $tenants = Tenant::withTrashed()->whereIn('id', $tenantIds)->get();
                foreach ($tenants as $tenant) {
                    $tenant->forceDelete();
                }
            }

            $user->forceDelete();
        });

        $this->info("Purged user {$user->id} ({$user->email}).");
    }
})->purpose('Permanently purge expired soft-deleted accounts (and owned tenants) after the grace period');

Schedule::command('dialer-incidents:prune')->daily();
Schedule::command('retention:enforce --execute')->dailyAt('02:10');
Schedule::command('inbox:sla-evaluate --execute')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('dsr:process --execute')->hourly()->withoutOverlapping();
Schedule::command('account:purge-expired --execute')->dailyAt('03:00');
Schedule::job(new RunCampaignDialerJob)->everyMinute()->withoutOverlapping();
