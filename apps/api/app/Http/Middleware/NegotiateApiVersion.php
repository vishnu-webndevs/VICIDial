<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NegotiateApiVersion
{
    private const MIME_V1 = 'application/vnd.wnddialer.v1+json';
    private const MIME_V2 = 'application/vnd.wnddialer.v2+json';

    public function handle(Request $request, Closure $next): Response
    {
        $acceptHeader = strtolower((string) $request->header('Accept', ''));
        $version = $this->resolveVersion($acceptHeader);

        if ($version === null) {
            return response()->json([
                'error' => [
                    'code' => 'API_VERSION_NOT_ACCEPTABLE',
                    'message' => 'Unsupported Accept header version. Use application/vnd.wnddialer.v1+json or application/vnd.wnddialer.v2+json.',
                ],
            ], 406);
        }

        $request->attributes->set('api_version', $version);
        $request->attributes->set('api_accept_mime', $version === 'v2' ? self::MIME_V2 : self::MIME_V1);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set('X-API-Version', $version);
        $response->headers->set('Vary', 'Accept');

        return $response;
    }

    private function resolveVersion(string $acceptHeader): ?string
    {
        if ($acceptHeader === '' || str_contains($acceptHeader, '*/*') || str_contains($acceptHeader, 'application/json')) {
            return 'v1';
        }

        if (str_contains($acceptHeader, self::MIME_V2)) {
            return 'v2';
        }

        if (str_contains($acceptHeader, self::MIME_V1)) {
            return 'v1';
        }

        if (str_contains($acceptHeader, 'application/vnd.wnddialer.')) {
            return null;
        }

        return 'v1';
    }
}
