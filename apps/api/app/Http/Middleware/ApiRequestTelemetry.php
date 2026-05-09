<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ApiRequestTelemetry
{
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = microtime(true);

        /** @var Response $response */
        $response = $next($request);

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        $requestId = (string) $request->attributes->get('request_id', '');
        $tenant = $request->attributes->get('tenant');
        $tenantId = is_object($tenant) && isset($tenant->id) ? (string) $tenant->id : null;

        Log::channel('apm')->info('api.request', [
            'request_id' => $requestId,
            'method' => $request->method(),
            'path' => $request->path(),
            'status' => $response->getStatusCode(),
            'duration_ms' => $durationMs,
            'tenant_id' => $tenantId,
            'user_id' => optional($request->user())->id,
            'ip' => $request->ip(),
        ]);

        $response->headers->set('X-Response-Time-Ms', (string) $durationMs);

        return $response;
    }
}
