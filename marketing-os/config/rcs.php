<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default RCS provider
    |--------------------------------------------------------------------------
    |
    | Used when a campaign does not specify one. Prefer a configured live
    | carrier (jio → airtel → vi), else sandbox.
    |
    */
    'default' => env('RCS_DEFAULT_PROVIDER', 'sandbox'),

    /*
    |--------------------------------------------------------------------------
    | RCS providers
    |--------------------------------------------------------------------------
    |
    | Multiple carrier / aggregator backends can be enabled side-by-side.
    | Campaigns pick one provider at create time.
    |
    */
    'providers' => [

        'sandbox' => [
            'label' => 'Test mode',
            'driver' => 'sandbox',
            'enabled' => true,
        ],

        'jio' => [
            'label' => 'Jio RCS',
            'driver' => 'http',
            'enabled' => (bool) env('RCS_JIO_ENABLED', false),
            'base_url' => env('RCS_JIO_BASE_URL', 'https://api.jio.com/rcs'),
            'client_id' => env('RCS_JIO_CLIENT_ID'),
            'client_secret' => env('RCS_JIO_CLIENT_SECRET'),
            'agent_id' => env('RCS_JIO_AGENT_ID'),
            'send_path' => env('RCS_JIO_SEND_PATH', '/v1/messages'),
            'auth' => env('RCS_JIO_AUTH', 'bearer'), // bearer|basic|header
        ],

        'airtel' => [
            'label' => 'Airtel RCS',
            'driver' => 'http',
            'enabled' => (bool) env('RCS_AIRTEL_ENABLED', false),
            'base_url' => env('RCS_AIRTEL_BASE_URL'),
            'client_id' => env('RCS_AIRTEL_CLIENT_ID'),
            'client_secret' => env('RCS_AIRTEL_CLIENT_SECRET'),
            'agent_id' => env('RCS_AIRTEL_AGENT_ID'),
            'send_path' => env('RCS_AIRTEL_SEND_PATH', '/v1/messages'),
            'auth' => env('RCS_AIRTEL_AUTH', 'bearer'),
        ],

        'vi' => [
            'label' => 'Vi RCS',
            'driver' => 'http',
            'enabled' => (bool) env('RCS_VI_ENABLED', false),
            'base_url' => env('RCS_VI_BASE_URL'),
            'client_id' => env('RCS_VI_CLIENT_ID'),
            'client_secret' => env('RCS_VI_CLIENT_SECRET'),
            'agent_id' => env('RCS_VI_AGENT_ID'),
            'send_path' => env('RCS_VI_SEND_PATH', '/v1/messages'),
            'auth' => env('RCS_VI_AUTH', 'bearer'),
        ],

        // Optional: route RCS text via Zavu SMS transport with intent metadata.
        'zavu' => [
            'label' => 'Zavu (SMS transport)',
            'driver' => 'zavu',
            'enabled' => (bool) env('RCS_ZAVU_ENABLED', false),
        ],
    ],
];
