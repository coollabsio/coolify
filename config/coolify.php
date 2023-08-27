<?php

return [
    'self_hosted' => env('SELF_HOSTED', true),
    'waitlist' => env('WAITLIST', false),
    'license_url' => 'https://license.coolify.io',
    'mux_enabled' => env('MUX_ENABLED', true),
    'dev_webhook' => env('SERVEO_URL'),
    'base_config_path' => env('BASE_CONFIG_PATH', '/data/coolify'),
];
