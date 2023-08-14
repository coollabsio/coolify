<?php

return [
    'self_hosted' => env('SELF_HOSTED', true),
    'license_url' => 'https://license.coolify.io',
    'lemon_squeezy_webhook_secret' => env('LEMON_SQUEEZY_WEBHOOK_SECRET'),
    'lemon_squeezy_checkout_id_monthly' => env('LEMON_SQUEEZY_CHECKOUT_ID_MONTHLY'),
    'lemon_squeezy_checkout_id_yearly' => env('LEMON_SQUEEZY_CHECKOUT_ID_YEARLY'),
    'mux_enabled' => env('MUX_ENABLED', true),
    'dev_webhook' => env('SERVEO_URL'),
    'base_config_path' => env('BASE_CONFIG_PATH', '/_data/coolify'),
    'dev_config_path' => env('DEV_CONFIG_PATH', './_data/coolify'),
];
