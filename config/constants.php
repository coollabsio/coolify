<?php

$release = json_decode(file_get_contents(dirname(__DIR__).'/release.json'), true);
$upstream = json_decode(file_get_contents(dirname(__DIR__).'/upstream.json'), true);

// 0 or an invalid value would remove the time limit of every remote command.
$sshCommandTimeout = (int) env('SSH_COMMAND_TIMEOUT', 3600);

return [
    'coolify' => [
        'version' => env('COOLIFY_VERSION') ?: $release['stable']['coolify'],
        'helper_version' => $upstream['helper']['version'],
        'railpack_version' => '0.43.0',
        'self_hosted' => env('SELF_HOSTED', true),
        'autoupdate' => env('AUTOUPDATE'),
        'base_config_path' => env('BASE_CONFIG_PATH', '/data/coolify'),
        // Development only: Docker volumes that the testing-host server mounts at /data/coolify and /data/coolify/backups.
        // devHostDockerPath() uses them only for that server. The defaults are the legacy docker-compose.dev.yml names.
        'dev_data_volume' => env('DEV_COOLIFY_DATA_VOLUME', 'coolify_dev_coolify_data'),
        'dev_backups_volume' => env('DEV_COOLIFY_BACKUPS_VOLUME', 'coolify_dev_backups_data'),
        // Development only: IP for the seeded sslip.io wildcard domain and application domains (for example a Tailscale IP).
        'dev_seed_ip' => env('DEV_SEED_IP') ?: '127.0.0.1',
        // The ACME directory that cluster ingress certificates come from. Empty turns HTTPS off. Development
        // defaults to empty, so dev clusters do not send orders to Let's Encrypt that must fail (scripts/dev-pebble).
        'acme_directory_url' => env('ACME_DIRECTORY_URL', env('APP_ENV', 'production') === 'local' ? '' : 'https://acme-v02.api.letsencrypt.org/directory'),
        // Optional PEM file of the only root CA to trust for that directory, for a test CA like Pebble (scripts/dev-pebble).
        'acme_ca_file' => env('ACME_CA_FILE'),
        'registry_url' => env('REGISTRY_URL', 'docker.io'),
        'helper_image' => env('HELPER_IMAGE', env('REGISTRY_URL', 'docker.io').'/coollabsio/coolify-helper'),
        // https://hub.docker.com/r/rclone/rclone/tags (also on ghcr.io with the same digest): copies backups to SFTP, FTPS, and WebDAV storages on the servers.
        'rclone_image' => env('RCLONE_IMAGE', env('REGISTRY_URL', 'docker.io').'/rclone/rclone:1.75.2@sha256:2687085f718d3c628f7fdfb77c52a1d344332aed543110bb88088f2c70d43eb5'),
        'cdn_url' => env('CDN_URL', 'https://cdn.coollabs.io'),
        'versions_url' => env('VERSIONS_URL', env('CDN_URL', 'https://cdn.coollabs.io').'/coolify/versions.json'),
        'upgrade_script_url' => env('UPGRADE_SCRIPT_URL', env('CDN_URL', 'https://cdn.coollabs.io').'/coolify/upgrade.sh'),
        'releases_url' => env('RELEASES_URL', 'https://cdn.coollabs.io/coolify/releases.json'),
        // "sequential" or "concurrent". Empty uses sequential on self-hosted and concurrent on Coolify Cloud.
        'scheduled_jobs_dispatch_mode' => env('SCHEDULED_JOBS_DISPATCH_MODE'),
    ],

    'cloud' => [
        // Days after a subscription ends (or after a never-paid team was created) before cloud:cleanup-unsubscribed-teams deletes the team's data.
        'data_retention_days' => (int) env('CLOUD_DATA_RETENTION_DAYS', 60),
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
        // Docker's default detach escape for `docker attach`. Pressing this
        // sequence (Ctrl-P, Ctrl-Q) leaves the container's main process running.
        'detach_keys' => 'ctrl-p,ctrl-q',
        // Recent log lines shown when a console (attach) session opens, so the
        // screen is not blank until the next line of live output arrives.
        'console_history_lines' => 200,
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

    // Scanner images run on managed servers, pinned by digest. Change them only with a Coolify release.
    'image_scan' => [
        'images' => [
            'syft' => ['repo' => 'anchore/syft', 'version' => '1.55.0', 'digest' => 'sha256:4cc92b64ca74ffca4c94d4c8fcc01f217731375300be14d0b6eeb2e82eaa110a'],
            'grype' => ['repo' => 'anchore/grype', 'version' => '0.121.0', 'digest' => 'sha256:621d56d1d68106b787022c81546f7e0a36202a25d1239f0c20195fab8c16f7ea'],
            'regctl' => ['repo' => 'regclient/regctl', 'version' => '0.11.7', 'digest' => 'sha256:45b57933c2b67bb1e280b4baaa220ef1ab212c81268ab19589fa1e83719c7c82'],
            'init' => ['repo' => 'alpine', 'version' => '3.22.7', 'digest' => 'sha256:2e0b81d60597f449baa56dc25d355367869d2dca4cd57e866f1e2b52af3eb55f'],
        ],
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
        // SSH host key check of managed servers: warn (connect, report a changed key), enforce (refuse a changed key), off.
        'host_key_checking' => strtolower(trim((string) env('SSH_HOST_KEY_CHECKING', 'warn'))),
    ],

    'invitation' => [
        'link' => [
            'expiration_days' => 3,
        ],
    ],

    'email_change' => [
        'verification_code_expiry_minutes' => 10,
    ],

    'sentinel' => [
        'dev_url' => env('DEV_SENTINEL_URL'),
        'host_enabled' => env('SENTINEL_HOST_ENABLED', false),
        // Fallback install image, used only when versions.json has no valid `coolify.sentinel-host` release.
        'host_image' => env('SENTINEL_HOST_IMAGE', 'ghcr.io/coollabsio/sentinel-host:main'),
        // Upgrades install `host_repository@digest` from the `coolify.sentinel-host` entry in versions.json.
        'host_repository' => env('SENTINEL_HOST_REPOSITORY', 'ghcr.io/coollabsio/sentinel-host'),
        // How long an upgraded Node has to reconnect to Flux with its capabilities before the previous Sentinel is restored (10 to 300).
        'upgrade_reconnect_timeout_seconds' => env('SENTINEL_UPGRADE_RECONNECT_TIMEOUT_SECONDS', 300),
        // How often (seconds) PushServerUpdateJob is force-dispatched even when
        // the container state hash is unchanged. Keeps exited-detection and
        // storage checks from going stale without writing every resource row on
        // every push.
        'push_force_interval_seconds' => env('SENTINEL_PUSH_FORCE_INTERVAL_SECONDS', 300),

    ],

    'flux' => [
        'renew_before_days' => 30,
        // The user that runs Flux (www-data of the Coolify image) owns the certificate files.
        'runtime_uid' => (int) env('FLUX_RUNTIME_UID', 65532),
        'binary' => env('FLUX_BINARY', '/usr/local/bin/flux'),
        // The default public port of the Flux URL. Flux listens on 7443 inside the Coolify container.
        'port' => (int) env('FLUX_PORT', 7443),
        'public_url' => env('FLUX_PUBLIC_URL'),
        'issuer' => env('FLUX_ISSUER', env('APP_ID')),
        // Without these variables, Coolify uses the values that it generated and stored in the instance settings.
        'signing_key_id' => env('FLUX_SIGNING_KEY_ID'),
        'signing_private_key' => env('FLUX_SIGNING_PRIVATE_KEY'),
        'signing_public_key' => env('FLUX_SIGNING_PUBLIC_KEY'),
        'previous_signing_key_id' => env('FLUX_PREVIOUS_SIGNING_KEY_ID'),
        'previous_signing_public_key' => env('FLUX_PREVIOUS_SIGNING_PUBLIC_KEY'),
        'internal_token' => env('FLUX_INTERNAL_TOKEN'),
        // Any other URL means that Flux runs outside this container (Coolify Cloud), so the s6 service stays down.
        'internal_url' => env('FLUX_INTERNAL_URL', 'http://127.0.0.1:7080'),
        'development_allow_plaintext' => env('FLUX_DEVELOPMENT_ALLOW_PLAINTEXT', false),
    ],

    'node' => [
        'operation_success_retention_days' => (int) env('NODE_OPERATION_SUCCESS_RETENTION_DAYS', 30),
        'operation_failure_retention_days' => (int) env('NODE_OPERATION_FAILURE_RETENTION_DAYS', 90),
        // Must be longer than the longest Node operation worker timeout (15 minutes).
        'operation_stale_after_minutes' => (int) env('NODE_OPERATION_STALE_AFTER_MINUTES', 20),
        // The one-shot build container that Sentinel starts for a Git build (decision 0006).
        'builder_image' => env('NODE_BUILDER_IMAGE', 'ghcr.io/coollabsio/coolify-builder:main-93ac30c@sha256:f74b546044522f3a4dbbb326feab4f1f84c222140bec5e534577366d4469b27a'),
        'build_timeout_minutes' => (int) env('NODE_BUILD_TIMEOUT_MINUTES', 30),
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
