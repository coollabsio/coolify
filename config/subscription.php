<?php

return [
    'provider' => env('SUBSCRIPTION_PROVIDER', null), // stripe, paddle, lemon
    // Stripe
    'stripe_api_key' => env('STRIPE_API_KEY', null),
    'stripe_webhook_secret' => env('STRIPE_WEBHOOK_SECRET', null),
    'stripe_price_id_basic_monthly' => env('STRIPE_PRICE_ID_BASIC_MONTHLY', null),
    'stripe_price_id_basic_yearly' => env('STRIPE_PRICE_ID_BASIC_YEARLY', null),
    'stripe_price_id_pro_monthly' => env('STRIPE_PRICE_ID_PRO_MONTHLY', null),
    'stripe_price_id_pro_yearly' => env('STRIPE_PRICE_ID_PRO_YEARLY', null),
    'stripe_price_id_ultimate_monthly' => env('STRIPE_PRICE_ID_ULTIMATE_MONTHLY', null),
    'stripe_price_id_ultimate_yearly' => env('STRIPE_PRICE_ID_ULTIMATE_YEARLY', null),
    'stripe_excluded_plans' => env('STRIPE_EXCLUDED_PLANS', null),

    'stripe_price_id_basic_monthly_old' => env('STRIPE_PRICE_ID_BASIC_MONTHLY_OLD', null),
    'stripe_price_id_basic_yearly_old' => env('STRIPE_PRICE_ID_BASIC_YEARLY_OLD', null),
    'stripe_price_id_pro_monthly_old' => env('STRIPE_PRICE_ID_PRO_MONTHLY_OLD', null),
    'stripe_price_id_pro_yearly_old' => env('STRIPE_PRICE_ID_PRO_YEARLY_OLD', null),
    'stripe_price_id_ultimate_monthly_old' => env('STRIPE_PRICE_ID_ULTIMATE_MONTHLY_OLD', null),
    'stripe_price_id_ultimate_yearly_old' => env('STRIPE_PRICE_ID_ULTIMATE_YEARLY_OLD', null),

    'stripe_price_id_dynamic_monthly' => env('STRIPE_PRICE_ID_DYNAMIC_MONTHLY', null),
    'stripe_price_id_dynamic_yearly' => env('STRIPE_PRICE_ID_DYNAMIC_YEARLY', null),

    // Paddle
    'paddle_vendor_id' => env('PADDLE_VENDOR_ID', null),
    'paddle_vendor_auth_code' => env('PADDLE_VENDOR_AUTH_CODE', null),
    'paddle_webhook_secret' => env('PADDLE_WEBHOOK_SECRET', null),
    'paddle_public_key' => env('PADDLE_PUBLIC_KEY', null),
    'paddle_price_id_basic_monthly' => env('PADDLE_PRICE_ID_BASIC_MONTHLY', null),
    'paddle_price_id_basic_yearly' => env('PADDLE_PRICE_ID_BASIC_YEARLY', null),
    'paddle_price_id_pro_monthly' => env('PADDLE_PRICE_ID_PRO_MONTHLY', null),
    'paddle_price_id_pro_yearly' => env('PADDLE_PRICE_ID_PRO_YEARLY', null),
    'paddle_price_id_ultimate_monthly' => env('PADDLE_PRICE_ID_ULTIMATE_MONTHLY', null),
    'paddle_price_id_ultimate_yearly' => env('PADDLE_PRICE_ID_ULTIMATE_YEARLY', null),

    // Lemon
    'lemon_squeezy_api_key' => env('LEMON_SQUEEZY_API_KEY', null),
    'lemon_squeezy_webhook_secret' => env('LEMON_SQUEEZY_WEBHOOK_SECRET', null),
    'lemon_squeezy_checkout_id_basic_monthly' => env('LEMON_SQUEEZY_CHECKOUT_ID_BASIC_MONTHLY', null),
    'lemon_squeezy_checkout_id_basic_yearly' => env('LEMON_SQUEEZY_CHECKOUT_ID_BASIC_YEARLY', null),
    'lemon_squeezy_checkout_id_pro_monthly' => env('LEMON_SQUEEZY_CHECKOUT_ID_PRO_MONTHLY', null),
    'lemon_squeezy_checkout_id_pro_yearly' => env('LEMON_SQUEEZY_CHECKOUT_ID_PRO_YEARLY', null),
    'lemon_squeezy_checkout_id_ultimate_monthly' => env('LEMON_SQUEEZY_CHECKOUT_ID_ULTIMATE_MONTHLY', null),
    'lemon_squeezy_checkout_id_ultimate_yearly' => env('LEMON_SQUEEZY_CHECKOUT_ID_ULTIMATE_YEARLY', null),
    'lemon_squeezy_basic_plan_ids' => env('LEMON_SQUEEZY_BASIC_PLAN_IDS', ''),
    'lemon_squeezy_pro_plan_ids' => env('LEMON_SQUEEZY_PRO_PLAN_IDS', ''),
    'lemon_squeezy_ultimate_plan_ids' => env('LEMON_SQUEEZY_ULTIMATE_PLAN_IDS', ''),
];
