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

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
    ],

    'zavu' => [
        'key' => env('ZAVUDEV_API_KEY'),
        'base_url' => env('ZAVU_BASE_URL', 'https://api.zavu.dev'),
        'webhook_secret' => env('ZAVU_WEBHOOK_SECRET'),
    ],

    'meta' => [
        'app_id' => env('META_APP_ID'),
        'app_secret' => env('META_APP_SECRET'),
        'whatsapp_phone_number_id' => env('META_WA_PHONE_NUMBER_ID'),
        'whatsapp_waba_id' => env('META_WA_WABA_ID'),
        'whatsapp_access_token' => env('META_WA_ACCESS_TOKEN'),
        'whatsapp_verify_token' => env('META_WA_VERIFY_TOKEN'),
        'whatsapp_app_secret' => env('META_WA_APP_SECRET'),
        'whatsapp_api_version' => env('META_WA_API_VERSION', 'v21.0'),
    ],

    'linkedin' => [
        'client_id' => env('LINKEDIN_CLIENT_ID'),
        'client_secret' => env('LINKEDIN_CLIENT_SECRET'),
    ],

    'x' => [
        'client_id' => env('X_CLIENT_ID'),
        'client_secret' => env('X_CLIENT_SECRET'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'pagespeed_key' => env('GOOGLE_PAGESPEED_API_KEY'),
    ],

    'dataforseo' => [
        'login' => env('DATAFORSEO_LOGIN'),
        'password' => env('DATAFORSEO_PASSWORD'),
        'base_url' => env('DATAFORSEO_BASE_URL', 'https://api.dataforseo.com'),
    ],

    'browserless' => [
        'token' => env('BROWSERLESS_TOKEN'),
        'url' => env('BROWSERLESS_URL'),
    ],

    // Free local headless Chrome for JS crawl (preferred over Browserless).
    'chrome' => [
        'binary' => env('CHROME_BINARY'),
        'virtual_time_budget_ms' => (int) env('CHROME_VIRTUAL_TIME_BUDGET_MS', 8000),
    ],

    // Askefy HTTP API (pages + posts) — Sanctum Bearer tokens
    'askefy' => [
        'base_url' => env('ASKEFY_BASE_URL', ''),
        'public_url' => env('ASKEFY_PUBLIC_URL', env('ASKEFY_BASE_URL', '')),
        'timeout' => (int) env('ASKEFY_TIMEOUT', 12),
        'connect_timeout' => (int) env('ASKEFY_CONNECT_TIMEOUT', 5),
    ],

    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'prices' => [
            // Legacy — Stripe no longer used for checkout; kept for old records.
            'starter' => env('STRIPE_PRICE_STARTER'),
            'growth' => env('STRIPE_PRICE_GROWTH'),
            'agency' => env('STRIPE_PRICE_AGENCY'),
            'month' => [
                'starter' => env('STRIPE_PRICE_STARTER'),
                'growth' => env('STRIPE_PRICE_GROWTH'),
                'agency' => env('STRIPE_PRICE_AGENCY'),
            ],
            'year' => [
                'starter' => env('STRIPE_PRICE_STARTER_YEARLY'),
                'growth' => env('STRIPE_PRICE_GROWTH_YEARLY'),
                'agency' => env('STRIPE_PRICE_AGENCY_YEARLY'),
            ],
        ],
    ],

    'razorpay' => [
        'key_id' => env('RAZORPAY_KEY_ID'),
        'key_secret' => env('RAZORPAY_KEY_SECRET'),
        'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),
        'plans' => [
            'starter' => env('RAZORPAY_PLAN_STARTER'),
            'growth' => env('RAZORPAY_PLAN_GROWTH'),
            'agency' => env('RAZORPAY_PLAN_AGENCY'),
            'month' => [
                'starter' => env('RAZORPAY_PLAN_STARTER'),
                'growth' => env('RAZORPAY_PLAN_GROWTH'),
                'agency' => env('RAZORPAY_PLAN_AGENCY'),
            ],
            'year' => [
                'starter' => env('RAZORPAY_PLAN_STARTER_YEARLY'),
                'growth' => env('RAZORPAY_PLAN_GROWTH_YEARLY'),
                'agency' => env('RAZORPAY_PLAN_AGENCY_YEARLY'),
            ],
        ],
    ],

];
