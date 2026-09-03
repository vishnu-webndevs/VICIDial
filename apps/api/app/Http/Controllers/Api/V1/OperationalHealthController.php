<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class OperationalHealthController extends Controller
{
    public function liveness(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'api',
            'check' => 'liveness',
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    public function readiness(): JsonResponse
    {
        $checks = [
            'database' => $this->databaseCheck(),
            'cache' => $this->cacheCheck(),
        ];
        $degraded = collect($checks)->contains(fn (string $value) => $value !== 'ok');

        return response()->json([
            'status' => $degraded ? 'degraded' : 'ok',
            'service' => 'api',
            'check' => 'readiness',
            'dependencies' => $checks,
            'timestamp' => now()->toIso8601String(),
        ], $degraded ? 503 : 200);
    }

    private function databaseCheck(): string
    {
        try {
            DB::select('SELECT 1');

            return 'ok';
        } catch (Throwable) {
            return 'down';
        }
    }

    private function cacheCheck(): string
    {
        $probeKey = 'health:cache:probe';

        try {
            Cache::put($probeKey, '1', 5);
            $value = Cache::get($probeKey);

            return $value === '1' ? 'ok' : 'down';
        } catch (Throwable) {
            return 'down';
        }
    }
    public function logs(\Illuminate\Http\Request $request): JsonResponse
    {
        $logPath = storage_path('logs/laravel.log');
        
        if (!file_exists($logPath)) {
            return response()->json(['logs' => 'Log file not found.']);
        }

        $tenant = $request->attributes->get('tenant');
        $tenantId = $tenant ? (string) $tenant->id : null;
        $user = $request->user();

        $isSuperAdmin = false;
        if ($user) {
            $roleSlug = (string) ($user->role?->slug ?? '');
            if (in_array($roleSlug, ['platform_super_admin', 'super_admin'], true) || (bool) ($user->is_super_admin ?? false)) {
                $isSuperAdmin = true;
            }
        }

        $maxLinesToScan = 2000;
        $file = new \SplFileObject($logPath, 'r');
        $file->seek(PHP_INT_MAX);
        $totalLines = $file->key();

        $startLine = max(0, $totalLines - $maxLinesToScan);
        $file->seek($startLine);

        $matchedLines = [];
        while (!$file->eof()) {
            $line = (string) $file->current();
            if (trim($line) !== '') {
                if ($isSuperAdmin) {
                    $matchedLines[] = $line;
                } elseif ($tenantId !== null && $tenantId !== '') {
                    $matchesThisTenant = str_contains($line, '"tenant_id":"' . $tenantId . '"')
                        || str_contains($line, "'tenant_id' => '" . $tenantId . "'")
                        || str_contains($line, '"tenant_id": "' . $tenantId . '"');

                    if ($matchesThisTenant) {
                        $matchedLines[] = $line;
                    }
                }
            }
            $file->next();
        }

        $outputLines = array_slice($matchedLines, -200);
        $content = implode('', $outputLines);

        return response()->json([
            'logs' => $content !== '' ? $content : 'No system log entries found for your company account.',
        ]);
    }
}
