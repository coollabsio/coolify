<?php

return [
    'self_hosted' => env('SELF_HOSTED', true),
    'license_url' => 'https://license.coolify.io',
    'lemon_squeezy_webhook_secret' => env('LEMON_SQUEEZY_WEBHOOK_SECRET'),
    'lemon_squeezy_checkout_id_1' => env('LEMON_SQUEEZY_CHECKOUT_ID_1'),
    'lemon_squeezy_checkout_id_2' => env('LEMON_SQUEEZY_CHECKOUT_ID_2'),
    'lemon_squeezy_checkout_id_3' => env('LEMON_SQUEEZY_CHECKOUT_ID_3'),
    'mux_enabled' => env('MUX_ENABLED', true),
    'dev_webhook' => env('SERVEO_URL'),
    'base_config_path' => env('BASE_CONFIG_PATH', '/data/coolify'),
    'proxy_config_path' => env('BASE_CONFIG_PATH', '/data/coolify') . "/proxy",
];
