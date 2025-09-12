<?php

return [
    'coolify' => [
        'version' => '4.0.0-beta.427',
        'helper_version' => '1.0.10',
        'realtime_version' => '1.0.10',
        'self_hosted' => env('SELF_HOSTED', true),
        'autoupdate' => env('AUTOUPDATE'),
        'base_config_path' => env('BASE_CONFIG_PATH', '/data/coolify'),
        'registry_url' => env('REGISTRY_URL', 'ghcr.io'),
        'helper_image' => env('HELPER_IMAGE', env('REGISTRY_URL', 'ghcr.io').'/coollabsio/coolify-helper'),
        'realtime_image' => env('REALTIME_IMAGE', env('REGISTRY_URL', 'ghcr.io').'/coollabsio/coolify-realtime'),
        'is_windows_docker_desktop' => env('IS_WINDOWS_DOCKER_DESKTOP', false),
        'releases_url' => 'https://cdn.coollabs.io/coolify/releases.json',
    ],

    'urls' => [
        'docs' => 'https://coolify.io/docs',
        'contact' => 'https://coolify.io/docs/contact',
    ],

    'services' => [
        // Temporary disabled until cache is implemented
        // 'official' => 'https://cdn.coollabs.io/coolify/service-templates.json',
        'official' => 'https://raw.githubusercontent.com/coollabsio/coolify/v4.x/templates/service-templates-latest.json',
        'file_name' => 'service-templates-latest.json',
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
        'mux_health_check_enabled' => env('SSH_MUX_HEALTH_CHECK_ENABLED', true),
        'mux_health_check_timeout' => env('SSH_MUX_HEALTH_CHECK_TIMEOUT', 5),
        'mux_max_age' => env('SSH_MUX_MAX_AGE', 1800), // 30 minutes
        'connection_timeout' => 10,
        'server_interval' => 20,
        'command_timeout' => 7200,
        'max_retries' => env('SSH_MAX_RETRIES', 3),
        'retry_base_delay' => env('SSH_RETRY_BASE_DELAY', 2), // seconds
        'retry_max_delay' => env('SSH_RETRY_MAX_DELAY', 30), // seconds
        'retry_multiplier' => env('SSH_RETRY_MULTIPLIER', 2),
    ],

    'invitation' => [
        'link' => [
            'base_url' => '/invitations/',
            'expiration_days' => 3,
        ],
    ],

    'email_change' => [
        'verification_code_expiry_minutes' => 10,
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
