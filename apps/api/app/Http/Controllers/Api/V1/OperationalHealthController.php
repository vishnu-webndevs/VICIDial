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
    public function logs(): JsonResponse
    {
        $logPath = storage_path('logs/laravel.log');
        
        if (!file_exists($logPath)) {
            return response()->json(['logs' => 'Log file not found.']);
        }

        $lines = 200;
        $file = new \SplFileObject($logPath, 'r');
        $file->seek(PHP_INT_MAX);
        $lastLine = $file->key();
        $linesToRead = max(0, $lastLine - $lines);
        
        $file->seek($linesToRead);
        $content = '';
        while (!$file->eof()) {
            $content .= $file->current();
            $file->next();
        }

        return response()->json(['logs' => $content]);
    }
}
