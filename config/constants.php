<?php

return [
    'docs' => [
        'base_url' => 'https://coolify.io/docs',
        'contact' => 'https://coolify.io/docs/contact',
    ],
    'ssh' => [
        'mux_persist_time' => env('SSH_MUX_PERSIST_TIME', '1m'),
        'connection_timeout' => 10,
        'server_interval' => 20,
        'command_timeout' => 7200,
    ],
    'waitlist' => [
        'expiration' => 10,
    ],
    'invitation' => [
        'link' => [
            'base_url' => '/invitations/',
            'expiration' => 10,
        ],
    ],
    'services' => [
        // Temporary disabled until cache is implemented
        // 'official' => 'https://cdn.coollabs.io/coolify/service-templates.json',
        'official' => 'https://raw.githubusercontent.com/coollabsio/coolify/main/templates/service-templates.json',
    ],
    'limits' => [
        'trial_period' => 0,
        'server' => [
            'zero' => 0,
            'self-hosted' => 999999999999,
            'basic' => env('LIMIT_SERVER_BASIC', 2),
            'pro' => env('LIMIT_SERVER_PRO', 10),
            'ultimate' => env('LIMIT_SERVER_ULTIMATE', 25),
            'dynamic' => env('LIMIT_SERVER_DYNAMIC', 2),
        ],
        'email' => [
            'zero' => true,
            'self-hosted' => true,
            'basic' => true,
            'pro' => true,
            'ultimate' => true,
            'dynamic' => true,
        ],
    ],
];
