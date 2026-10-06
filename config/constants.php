<?php

// 0 or an invalid value would remove the time limit of every remote command.
$sshCommandTimeout = (int) env('SSH_COMMAND_TIMEOUT', 3600);

return [
    'coolify' => [
        'version' => env('COOLIFY_VERSION') ?: '4.4.0',
        'helper_version' => '1.0.17',
        'railpack_version' => '0.23.0',
        'self_hosted' => env('SELF_HOSTED', true),
        'autoupdate' => env('AUTOUPDATE'),
        'base_config_path' => env('BASE_CONFIG_PATH', '/data/coolify'),
        // Development only: Docker volumes that the testing-host server mounts at /data/coolify and /data/coolify/backups.
        // devHostDockerPath() uses them only for that server. The defaults are the legacy docker-compose.dev.yml names.
        'dev_data_volume' => env('DEV_COOLIFY_DATA_VOLUME', 'coolify_dev_coolify_data'),
        'dev_backups_volume' => env('DEV_COOLIFY_BACKUPS_VOLUME', 'coolify_dev_backups_data'),
        'registry_url' => env('REGISTRY_URL', 'docker.io'),
        'helper_image' => env('HELPER_IMAGE', env('REGISTRY_URL', 'docker.io').'/coollabsio/coolify-helper'),
        'is_windows_docker_desktop' => env('IS_WINDOWS_DOCKER_DESKTOP', false),
        'cdn_url' => env('CDN_URL', 'https://cdn.coollabs.io'),
        'versions_url' => env('VERSIONS_URL', env('CDN_URL', 'https://cdn.coollabs.io').'/coolify/versions.json'),
        'upgrade_script_url' => env('UPGRADE_SCRIPT_URL', env('CDN_URL', 'https://cdn.coollabs.io').'/coolify/upgrade.sh'),
        'releases_url' => env('RELEASES_URL', 'https://cdn.coollabs.io/coolify/releases.json'),
    ],

    'urls' => [
        'docs' => 'https://coolify.io/docs',
        'contact' => 'https://coolify.io/docs/contact',
    ],

    'services' => [
        'official' => 'https://cdn.coollabs.io/coolify/service-templates-latest.json',
        'file_name' => 'service-templates-latest.json',
        // Shared across HTTP/Horizon nodes when CACHE_DRIVER is redis (default).
        'cache_key' => 'coolify:service-templates-bundle',
    ],

    'terminal' => [
        'protocol' => env('TERMINAL_PROTOCOL'),
        'host' => env('TERMINAL_HOST'),
        'port' => env('TERMINAL_PORT'),
        'command_timeout' => 0,
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
        'stop_timeout_flag_since' => '28.0.0',
    ],

    'ssh' => [
        'mux_enabled' => env('MUX_ENABLED', env('SSH_MUX_ENABLED', true)),
        'mux_persist_time' => env('SSH_MUX_PERSIST_TIME', 3600),
        'mux_health_check_enabled' => env('SSH_MUX_HEALTH_CHECK_ENABLED', true),
        'mux_health_check_timeout' => env('SSH_MUX_HEALTH_CHECK_TIMEOUT', 5),
        'mux_lock_ttl' => env('SSH_MUX_LOCK_TTL', 30), // lock auto-release, seconds
        'mux_lock_timeout' => env('SSH_MUX_LOCK_TIMEOUT', 10), // max wait for lock, seconds
        'mux_orphan_min_age' => env('SSH_MUX_ORPHAN_MIN_AGE', 600), // min process age before reaping orphans, seconds
        'mux_orphan_reap_enabled' => env('SSH_MUX_ORPHAN_REAP_ENABLED', false), // false = dry-run, only log orphans
        'connection_timeout' => 10,
        'server_interval' => 20,
        'command_timeout' => $sshCommandTimeout > 0 ? $sshCommandTimeout : 3600,
        'max_retries' => env('SSH_MAX_RETRIES', 3),
        'retry_base_delay' => env('SSH_RETRY_BASE_DELAY', 2), // seconds
        'retry_max_delay' => env('SSH_RETRY_MAX_DELAY', 30), // seconds
        'retry_multiplier' => env('SSH_RETRY_MULTIPLIER', 2),
    ],

    'invitation' => [
        'link' => [
            'expiration_days' => 3,
        ],
    ],

    'email_change' => [
        'verification_code_expiry_minutes' => 10,
    ],

    'sentry' => [
        'sentry_dsn' => env('SENTRY_DSN'),
    ],

    'sentinel' => [
        'dev_url' => env('DEV_SENTINEL_URL'),
        // How often (seconds) PushServerUpdateJob is force-dispatched even when
        // the container state hash is unchanged. Keeps exited-detection and
        // storage checks from going stale without writing every resource row on
        // every push.
        'push_force_interval_seconds' => env('SENTINEL_PUSH_FORCE_INTERVAL_SECONDS', 300),

    ],

    'github_runner' => [
        // GitHub stops sending jobs to runners that are more than 30 days behind, so the default follows
        // "latest" and is pulled for every runner. Users can pin a tag in the runner settings.
        'image' => env('GITHUB_RUNNER_IMAGE', 'ghcr.io/actions/actions-runner:latest'),
        'dind_image' => env('GITHUB_RUNNER_DIND_IMAGE', 'docker:29.8-dind'),
        'sysbox' => [
            'version' => '0.7.1',
            'checksums' => [
                'amd64' => '9d6d5484f980d0a17f86c492c1262015c2afb66280bdb97215b79fde6a0261c5',
                'arm64' => '04ca894ae0b53f0fa54eaacc173ce40363c9a95ea5450f773716a84ef650a69b',
            ],
        ],
    ],

    'webhooks' => [
        'feedback_discord_webhook' => env('FEEDBACK_DISCORD_WEBHOOK'),
        'dev_webhook' => env('SERVEO_URL'),
    ],

    'cloudflare' => [
        'api_token' => env('CLOUDFLARE_API_TOKEN'),
        'zone_id' => env('CLOUDFLARE_ZONE_ID'),
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
