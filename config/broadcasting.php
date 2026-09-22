<?php

/*
 * Server-side host for the bundled Reverb server. Older installs could set
 * PUSHER_BACKEND_HOST to the removed "coolify-realtime" container, so that
 * value is treated as unset. The browser-facing PUSHER_HOST must never be used here.
 */
$backendHost = env('PUSHER_BACKEND_HOST');
if (blank($backendHost) || $backendHost === 'coolify-realtime') {
    $backendHost = '127.0.0.1';
}

return [

    /*
    |--------------------------------------------------------------------------
    | Default Broadcaster
    |--------------------------------------------------------------------------
    |
    | This option controls the default broadcaster that will be used by the
    | framework when an event needs to be broadcast. You may set this to
    | any of the connections defined in the "connections" array below.
    |
    | Supported: "reverb", "pusher", "ably", "redis", "log", "null"
    |
    */

    'default' => env('BROADCAST_CONNECTION', env('BROADCAST_DRIVER', 'reverb')),

    /*
    |--------------------------------------------------------------------------
    | Broadcast Connections
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the broadcast connections that will be used
    | to broadcast events to other systems or over websockets. Samples of
    | each available type of connection are provided inside this array.
    |
    */

    'connections' => [

        'reverb' => [
            'driver' => 'reverb',
            'key' => env('PUSHER_APP_KEY', 'coolify'),
            'secret' => env('PUSHER_APP_SECRET', 'coolify'),
            'app_id' => env('PUSHER_APP_ID', 'coolify'),
            'options' => [
                'host' => $backendHost,
                'port' => env('PUSHER_BACKEND_PORT', 6001),
                'scheme' => env('PUSHER_BACKEND_SCHEME', 'http'),
                'encrypted' => true,
                'useTLS' => env('PUSHER_BACKEND_SCHEME', 'http') === 'https',
                'path' => '',
            ],
            'client_options' => [
                // Guzzle client options: https://docs.guzzlephp.org/en/stable/request-options.html
            ],
        ],

        // Legacy connection name (BROADCAST_DRIVER=pusher). Reverb speaks the Pusher
        // protocol, so this keeps pointing at the bundled server like it did before.
        'pusher' => [
            'driver' => 'pusher',
            'key' => env('PUSHER_APP_KEY', 'coolify'),
            'secret' => env('PUSHER_APP_SECRET', 'coolify'),
            'app_id' => env('PUSHER_APP_ID', 'coolify'),
            'options' => [
                'host' => $backendHost,
                'port' => env('PUSHER_BACKEND_PORT', 6001),
                'scheme' => env('PUSHER_BACKEND_SCHEME', 'http'),
                'encrypted' => true,
                'useTLS' => env('PUSHER_BACKEND_SCHEME', 'http') === 'https',
            ],
            'client_options' => [
                // Guzzle client options: https://docs.guzzlephp.org/en/stable/request-options.html
            ],
        ],

        'ably' => [
            'driver' => 'ably',
            'key' => env('ABLY_KEY'),
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => 'default',
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
