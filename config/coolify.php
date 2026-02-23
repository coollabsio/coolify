<?php

declare(strict_types=1);

return [
    'version' => '5.0.0-alpha.1',
    'helper_version' => '2.0.0',
    'self_hosted' => env('SELF_HOSTED', true),
    'autoupdate' => env('AUTOUPDATE_ENABLED', false),
    'cdn_url' => env('CDN_URL', 'https://cdn.coollabs.io/v5.x'),

    'migrations' => [
        'enabled' => env('MIGRATIONS_ENABLED', true),
    ],

    'seeders' => [
        'enabled' => env('SEEDERS_ENABLED', true),
    ],

    'scheduler' => [
        'enabled' => env('SCHEDULER_ENABLED', true),
    ],

    'horizon' => [
        'enabled' => env('HORIZON_ENABLED', true),
    ],
];
