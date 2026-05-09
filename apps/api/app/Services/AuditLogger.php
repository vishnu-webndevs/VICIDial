<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\TenantSetting;
use Illuminate\Support\Facades\Mail;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AuditLogger
{
    public function log(
        string $action,
        string $resourceType,
        ?string $resourceId,
        ?string $tenantId,
        ?string $actorId,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?Request $request = null
    ): void {
        $auditLog = AuditLog::query()->create([
            'tenant_id' => $tenantId,
            'actor_id' => $actorId,
            'actor_type' => 'user',
            'action' => $action,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $request?->ip(),
            'user_agent' => Str::limit((string) $request?->userAgent(), 500, ''),
            'correlation_id' => $request?->header('X-Correlation-Id'),
        ]);

        if (! empty($tenantId)) {
            $notification = Notification::query()->create([
                'tenant_id' => $tenantId,
                'user_id' => $actorId,
                'type' => 'audit_event',
                'title' => Str::headline($action),
                'message' => "Action {$action} recorded for {$resourceType}.",
                'metadata' => [
                    'resource_type' => $resourceType,
                    'resource_id' => $resourceId,
                    'audit_log_id' => $auditLog->id,
                ],
            ]);

            $this->sendEmailNotification($tenantId, $notification->title, $notification->message);
        }
    }

    private function sendEmailNotification(string $tenantId, string $title, string $message): void
    {
        $alertEmail = TenantSetting::query()
            ->where('tenant_id', $tenantId)
            ->value('alert_email');

        if (! $alertEmail) {
            return;
        }

        try {
            Mail::raw(
                "WND Dialer alert\n\n{$title}\n\n{$message}",
                static function ($mail) use ($alertEmail, $title): void {
                    $mail->to($alertEmail)->subject("WND Dialer Notification: {$title}");
                }
            );
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
