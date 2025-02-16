<?php

return [
    'coolify' => [
        'version' => '4.0.0-beta.393',
        'helper_version' => '1.0.6',
        'realtime_version' => '1.0.5',
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

    'migration' => [
        'is_migration_enabled' => env('MIGRATION_ENABLED', true),
    ],

    'seeder' => [
        'is_seeder_enabled' => env('SEEDER_ENABLED', true),
    ],

    'horizon' => [
        'is_horizon_enabled' => env('HORIZON_ENABLED', true),
        'is_scheduler_enabled' => env('SCHEDULER_ENABLED', true),
    ],

    'docker' => [
        'minimum_required_version' => '24.0',
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
