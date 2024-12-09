<?php

return [
    'coolify' => [
        'version' => '4.0.0-beta.377',
        'self_hosted' => env('SELF_HOSTED', true),
        'autoupdate' => env('AUTOUPDATE'),
        'base_config_path' => env('BASE_CONFIG_PATH', '/data/coolify'),
        'helper_image' => env('HELPER_IMAGE', 'ghcr.io/coollabsio/coolify-helper'),
        'is_windows_docker_desktop' => env('IS_WINDOWS_DOCKER_DESKTOP', false),
    ],

    'urls' => [
        'docs' => 'https://coolify.io/docs',
        'contact' => 'https://coolify.io/docs/contact',
    ],

    'services' => [
        // Temporary disabled until cache is implemented
        // 'official' => 'https://cdn.coollabs.io/coolify/service-templates.json',
        'official' => 'https://raw.githubusercontent.com/coollabsio/coolify/main/templates/service-templates.json',
    ],

    'terminal' => [
        'protocol' => env('TERMINAL_PROTOCOL'),
        'host' => env('TERMINAL_HOST'),
        'port' => env('TERMINAL_PORT'),
    ],

    'pusher' => [
        'host' => env('PUSHER_HOST'),
        'port' => env('PUSHER_PORT'),
        'app_key' => env('PUSHER_APP_KEY'),
    ],

    'horizon' => [
        'is_horizon_enabled' => env('HORIZON_ENABLED', true),
        'is_scheduler_enabled' => env('SCHEDULER_ENABLED', true),
    ],

    'docker' => [
        'minimum_required_version' => '26.0',
        'version' => '27.0',
    ],

    'ssh' => [
        'mux_enabled' => env('MUX_ENABLED', env('SSH_MUX_ENABLED', true)),
        'mux_persist_time' => env('SSH_MUX_PERSIST_TIME', 3600),
        'connection_timeout' => 10,
        'server_interval' => 20,
        'command_timeout' => 7200,
    ],

    'invitation' => [
        'link' => [
            'base_url' => '/invitations/',
            'expiration_days' => 3,
        ],
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

    'waitlist' => [
        'enabled' => env('WAITLIST', false),
        'expiration' => 10,
    ],

    'sentry' => [
        'sentry_dsn' => env('SENTRY_DSN'),
    ],

    'webhooks' => [
        'feedback_discord_webhook' => env('FEEDBACK_DISCORD_WEBHOOK'),
        'dev_webhook' => env('SERVEO_URL'),
    ],

    'bunny' => [
        'storage_api_key' => env('BUNNY_STORAGE_API_KEY'),
        'api_key' => env('BUNNY_API_KEY'),
    ],
];
