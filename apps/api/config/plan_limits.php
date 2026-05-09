<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Route Quota Map
    |--------------------------------------------------------------------------
    |
    | Keys use "METHOD route/uri/pattern". Supports "*" wildcard.
    |
    */
    'route_feature_map' => [
        'POST agents' => 'max_agents',
        'POST campaigns' => 'max_campaigns',
        'POST api-tokens' => 'max_api_tokens',
        'POST providers' => 'max_providers',
        'POST webhooks/replay' => 'max_webhook_replays',
    ],

    /*
    |--------------------------------------------------------------------------
    | Live Usage Sources
    |--------------------------------------------------------------------------
    |
    | For each feature key, define how current usage is calculated.
    |
    */
    'usage_sources' => [
        'max_agents' => [
            'table' => 'agents',
            'tenant_column' => 'tenant_id',
            'active_column' => 'status',
            'active_value' => 'active',
        ],
        'max_campaigns' => [
            'table' => 'campaigns',
            'tenant_column' => 'tenant_id',
            'active_column' => 'status',
            'active_values' => ['draft', 'scheduled', 'running', 'paused'],
        ],
        'max_api_tokens' => [
            'table' => 'personal_access_tokens',
            'tenant_column' => 'tenant_id',
        ],
        'max_providers' => [
            'table' => 'provider_accounts',
            'tenant_column' => 'tenant_id',
            'active_column' => 'status',
            'active_values' => ['active', 'validated'],
        ],
    ],
];
