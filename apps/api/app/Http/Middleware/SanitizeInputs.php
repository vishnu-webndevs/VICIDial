<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SanitizeInputs
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Bypass sanitization for webhooks, sandbox routes, and health checks
        if ($request->is('*webhooks*') || $request->is('*sandbox*') || $request->is('*health*') || $request->is('up')) {
            return $next($request);
        }

        $input = $request->all();

        array_walk_recursive($input, function (&$value) {
            if (is_string($value)) {
                $value = strip_tags($value);
            }
        });

        $request->merge($input);

        return $next($request);
    }
}
