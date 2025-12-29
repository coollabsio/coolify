<?php

return [
    'coolify' => [
        'version' => '4.0.0-beta.459',
        'helper_version' => '1.0.12',
        'realtime_version' => '1.0.10',
        'self_hosted' => env('SELF_HOSTED', true),
        'autoupdate' => env('AUTOUPDATE'),
        'base_config_path' => env('BASE_CONFIG_PATH', '/data/coolify'),
        'registry_url' => env('REGISTRY_URL', 'ghcr.io'),
        'helper_image' => env('HELPER_IMAGE', env('REGISTRY_URL', 'ghcr.io').'/coollabsio/coolify-helper'),
        'realtime_image' => env('REALTIME_IMAGE', env('REGISTRY_URL', 'ghcr.io').'/coollabsio/coolify-realtime'),
        'is_windows_docker_desktop' => env('IS_WINDOWS_DOCKER_DESKTOP', false),
        'cdn_url' => env('CDN_URL', 'https://cdn.coollabs.io'),
        'versions_url' => env('VERSIONS_URL', env('CDN_URL', 'https://cdn.coollabs.io').'/coolify/versions.json'),
        'upgrade_script_url' => env('UPGRADE_SCRIPT_URL', env('CDN_URL', 'https://cdn.coollabs.io').'/coolify/upgrade.sh'),
        'releases_url' => 'https://cdn.coolify.io/releases.json',
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
        'command_timeout' => 3600,
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

    'server_checks' => [
        // Notification delay configuration for parallel server checks
        // Used for Traefik version checks and other future server check jobs
        // These settings control how long to wait before sending notifications
        // after dispatching parallel check jobs for all servers

        // Minimum delay in seconds (120s = 2 minutes)
        // Accounts for job processing time, retries, and network latency
        'notification_delay_min' => 120,

        // Maximum delay in seconds (300s = 5 minutes)
        // Prevents excessive waiting for very large server counts
        'notification_delay_max' => 300,

        // Scaling factor: seconds to add per server (0.2)
        // Formula: delay = min(max, max(min, serverCount * scaling))
        // Examples:
        //   - 100 servers: 120s (uses minimum)
        //   - 1000 servers: 200s
        //   - 2000 servers: 300s (hits maximum)
        'notification_delay_scaling' => 0.2,
    ],
];
