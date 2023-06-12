<?php

return [
    'self_hosted' => env('SELF_HOSTED', true),
    'mux_enabled' => env('MUX_ENABLED', true),
    'dev_webhook' => env('SERVEO_URL'),
    'base_config_path' => env('BASE_CONFIG_PATH', '/data/coolify'),
    'proxy_config_path' => env('BASE_CONFIG_PATH', '/data/coolify') . "/proxy",
];
