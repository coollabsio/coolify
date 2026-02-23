<?php

declare(strict_types=1);

return [
    'version' => '5.0.0-alpha.1',
    'helper_version' => '2.0.0',
    'self_hosted' => env('SELF_HOSTED', true),
    'autoupdate' => env('AUTOUPDATE', false),
    'cdn_url' => env('CDN_URL', 'https://cdn.coollabs.io/v5.x'),

    'migrations' => [
        'enabled' => env('IS_MIGRATIONS_ENABLED', true),
    ],

    'seeders' => [
        'enabled' => env('IS_SEEDERS_ENABLED', true),
    ],

    'scheduler' => [
        'enabled' => env('IS_SCHEDULER_ENABLED', true),
    ],

    'horizon' => [
        'enabled' => env('IS_HORIZON_ENABLED', true),
    ],
];
