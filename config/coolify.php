<?php

return [
    'docs' => 'https://coolify.io/docs/',
    'contact' => 'https://coolify.io/docs/contact',
    'feedback_discord_webhook' => env('FEEDBACK_DISCORD_WEBHOOK'),
    'self_hosted' => env('SELF_HOSTED', true),
    'waitlist' => env('WAITLIST', false),
    'license_url' => 'https://licenses.coollabs.io',
    'mux_enabled' => env('MUX_ENABLED', true),
    'dev_webhook' => env('SERVEO_URL'),
    'is_windows_docker_desktop' => env('IS_WINDOWS_DOCKER_DESKTOP', false),
    'base_config_path' => env('BASE_CONFIG_PATH', '/data/coolify'),
    'helper_image' => env('HELPER_IMAGE', 'ghcr.io/coollabsio/coolify-helper'),
    'is_horizon_enabled' => env('HORIZON_ENABLED', true),
    'is_scheduler_enabled' => env('SCHEDULER_ENABLED', true),
];
