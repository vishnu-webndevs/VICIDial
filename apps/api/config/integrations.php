<?php

return [
    'mode' => env('INTEGRATION_MODE', 'auto'),
    'strict_validation' => (bool) env('INTEGRATION_STRICT_VALIDATION', true),
    'sandbox' => [
        'base_url' => env('SANDBOX_BASE_URL', 'http://localhost:8000/api/sandbox/third-party'),
    ],
    'dialer_incidents' => [
        'retention_days' => (int) env('DIALER_INCIDENT_RETENTION_DAYS', 90),
    ],
];
