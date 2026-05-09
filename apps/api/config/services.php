<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'stripe' => [
        'secret_key' => env('STRIPE_SECRET_KEY'),
        'publishable_key' => env('STRIPE_PUBLISHABLE_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    'twilio' => [
        'sid' => env('TWILIO_SID'),
        'token' => env('TWILIO_TOKEN'),
        'from' => env('TWILIO_FROM'),
        'whatsapp_from' => env('TWILIO_WHATSAPP_FROM'),
    ],

    'part3' => [
        'enabled' => (bool) env('PART3_INTEGRATIONS_ENABLED', false),
        'auth_token' => env('PART3_INTEGRATIONS_AUTH_TOKEN'),
        'messaging' => [
            'sms' => [
                'enabled' => (bool) env('PART3_SMS_ENABLED', false),
                'inbound_url' => env('PART3_SMS_INBOUND_URL'),
                'outbound_url' => env('PART3_SMS_OUTBOUND_URL'),
            ],
            'whatsapp' => [
                'enabled' => (bool) env('PART3_WHATSAPP_ENABLED', false),
                'inbound_url' => env('PART3_WHATSAPP_INBOUND_URL'),
                'outbound_url' => env('PART3_WHATSAPP_OUTBOUND_URL'),
            ],
        ],
        'teams' => [
            'enabled' => (bool) env('PART3_TEAMS_ENABLED', false),
            'url' => env('PART3_TEAMS_URL'),
        ],
        'ai' => [
            'enabled' => (bool) env('PART3_AI_ENABLED', false),
            'url' => env('PART3_AI_URL'),
        ],
        'graph' => [
            'enabled' => (bool) env('PART3_GRAPH_ENABLED', false),
            'availability_url' => env('PART3_GRAPH_AVAILABILITY_URL'),
            'booking_url' => env('PART3_GRAPH_BOOKING_URL'),
        ],
        'workflow' => [
            'enabled' => (bool) env('PART3_WORKFLOW_ENABLED', false),
            'url' => env('PART3_WORKFLOW_URL'),
        ],
        'reporting' => [
            'enabled' => (bool) env('PART3_REPORTING_ENABLED', false),
            'url' => env('PART3_REPORTING_URL'),
        ],
        'governance' => [
            'enabled' => (bool) env('PART3_GOVERNANCE_ENABLED', false),
            'retention_url' => env('PART3_GOVERNANCE_RETENTION_URL'),
            'drill_url' => env('PART3_GOVERNANCE_DRILL_URL'),
        ],
    ],

];
