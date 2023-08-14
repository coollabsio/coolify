<?php

return [
    'self_hosted' => env('SELF_HOSTED', true),
    'license_url' => 'https://license.coolify.io',
    'lemon_squeezy_webhook_secret' => env('LEMON_SQUEEZY_WEBHOOK_SECRET', null),
    'lemon_squeezy_checkout_id_monthly_basic' => env('LEMON_SQUEEZY_CHECKOUT_ID_MONTHLY_BASIC', null),
    'lemon_squeezy_checkout_id_monthly_pro' => env('LEMON_SQUEEZY_CHECKOUT_ID_MONTHLY_PRO', null),
    'lemon_squeezy_checkout_id_monthly_ultimate' => env('LEMON_SQUEEZY_CHECKOUT_ID_MONTHLY_ULTIMATE', null),
    'lemon_squeezy_checkout_id_yearly_basic' => env('LEMON_SQUEEZY_CHECKOUT_ID_YEARLY_BASIC', null),
    'lemon_squeezy_checkout_id_yearly_pro' => env('LEMON_SQUEEZY_CHECKOUT_ID_YEARLY_PRO', null),
    'lemon_squeezy_checkout_id_yearly_ultimate' => env('LEMON_SQUEEZY_CHECKOUT_ID_YEARLY_ULTIMATE', null),
    'lemon_squeezy_basic_plan_ids' => env('LEMON_SQUEEZY_BASIC_PLAN_IDS', ""),
    'lemon_squeezy_pro_plan_ids' => env('LEMON_SQUEEZY_PRO_PLAN_IDS', ""),
    'lemon_squeezy_ultimate_plan_ids' => env('LEMON_SQUEEZY_ULTIMATE_PLAN_IDS', ""),
    'mux_enabled' => env('MUX_ENABLED', true),
    'dev_webhook' => env('SERVEO_URL'),
    'base_config_path' => env('BASE_CONFIG_PATH', '/_data/coolify'),
    'dev_config_path' => env('DEV_CONFIG_PATH', './_data/coolify'),
];
