<?php

return [
    'provider' => env('SUBSCRIPTION_PROVIDER', null), // stripe

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
];
