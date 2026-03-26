<?php

return [
    'provider' => env('SUBSCRIPTION_PROVIDER', null), // stripe

    // Stripe
    'stripe_api_key' => env('STRIPE_API_KEY', null),
    'stripe_webhook_secret' => env('STRIPE_WEBHOOK_SECRET', null),
    'stripe_excluded_plans' => env('STRIPE_EXCLUDED_PLANS', null),
    'stripe_price_id_dynamic_monthly' => env('STRIPE_PRICE_ID_DYNAMIC_MONTHLY', null),
    'stripe_price_id_dynamic_yearly' => env('STRIPE_PRICE_ID_DYNAMIC_YEARLY', null),

    // Mailcheep
    'mailcheep_api_key' => env('MAILCHEEP_API_KEY'),
    'mailcheep_list_subscribed' => env('MAILCHEEP_LIST_SUBSCRIBED'),
    'mailcheep_list_churned' => env('MAILCHEEP_LIST_CHURNED'),
];
