<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:3000')))),
    'allowed_origins_patterns' => array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGIN_PATTERNS', '')))),
    'allowed_headers' => ['*'],
    'exposed_headers' => ['X-API-Version', 'X-Request-Id', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset'],
    'max_age' => (int) env('CORS_MAX_AGE', 0),
    'supports_credentials' => filter_var(env('CORS_SUPPORTS_CREDENTIALS', true), FILTER_VALIDATE_BOOLEAN),
];
