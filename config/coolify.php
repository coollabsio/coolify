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
    'coolify_root_path' => env('COOLIFY_ROOT_PATH', '/data/coolify'),
    'helper_image' => env('HELPER_IMAGE', 'ghcr.io/coollabsio/coolify-helper:latest'),
    'is_horizon_enabled' => env('HORIZON_ENABLED', true),
    'is_scheduler_enabled' => env('SCHEDULER_ENABLED', true),
];
