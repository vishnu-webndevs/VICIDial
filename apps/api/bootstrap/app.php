<?php

use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureAdminMembership;
use App\Http\Middleware\EnsureSuperAdminMembership;
use App\Http\Middleware\EnsureUsageQuota;
use App\Http\Middleware\NegotiateApiVersion;
use App\Http\Middleware\ResolveTenantContext;
use App\Http\Middleware\AttachRequestId;
use App\Http\Middleware\ApiRequestTelemetry;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SanitizeInputs;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: (string) env('API_PREFIX', 'api'),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->throttleApi('api');

        $middleware->api(prepend: [
            AttachRequestId::class,
            ApiRequestTelemetry::class,
            SecurityHeaders::class,
            SanitizeInputs::class,
        ]);

        $middleware->alias([
            'tenant.resolve' => ResolveTenantContext::class,
            'permission' => EnsurePermission::class,
            'tenant.admin' => EnsureAdminMembership::class,
            'tenant.super_admin' => EnsureSuperAdminMembership::class,
            'usage.quota' => EnsureUsageQuota::class,
            'api.version' => NegotiateApiVersion::class,
        ]);
        $middleware->redirectGuestsTo('/login');

        // Trust all proxies and set HTTPS
        $middleware->trustProxies(at: '*');
        $middleware->trustProxies(headers: Illuminate\Http\Request::HEADER_X_FORWARDED_ALL);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'UNAUTHENTICATED',
                        'message' => 'Unauthenticated.',
                    ],
                    'meta' => [
                        'request_id' => (string) $request->attributes->get('request_id', ''),
                    ],
                ], 401);
            }

            return null;
        });

        $exceptions->render(function (ValidationException $exception, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            $requestId = (string) $request->attributes->get('request_id', '');

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => $exception->getMessage(),
                    'details' => $exception->errors(),
                ],
                'meta' => [
                    'request_id' => $requestId,
                ],
            ], 422);
        });

        $exceptions->render(function (\Throwable $exception, Request $request) {
            if ($exception instanceof \Illuminate\Http\Exceptions\HttpResponseException) {
                return $exception->getResponse();
            }

            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            $status = method_exists($exception, 'getStatusCode')
                ? max(400, min(599, (int) $exception->getStatusCode()))
                : 500;
            $requestId = (string) $request->attributes->get('request_id', '');

            Log::error('Unhandled API exception', [
                'request_id' => $requestId,
                'path' => $request->path(),
                'method' => $request->method(),
                'exception' => get_class($exception),
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => $status >= 500 ? 'INTERNAL_SERVER_ERROR' : 'REQUEST_FAILED',
                    'message' => $status >= 500 ? 'An unexpected server error occurred.' : $exception->getMessage(),
                ],
                'meta' => [
                    'request_id' => $requestId,
                ],
            ], $status);
        });
    })->create();
