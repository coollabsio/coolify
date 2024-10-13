<?php

return [

    // @see https://docs.sentry.io/product/sentry-basics/dsn-explainer/
    'dsn' => 'https://89552af6db48f4ca6a871ec0fc42964d@o1082494.ingest.us.sentry.io/4505347448045568',

    // The release version of your application
    // Example with dynamic git hash: trim(exec('git --git-dir ' . base_path('.git') . ' log --pretty="%h" -n1 HEAD'))
    'release' => '4.0.0-beta.360',

    // When left empty or `null` the Laravel environment will be used
    'environment' => config('app.env'),

    'breadcrumbs' => [
        // Capture Laravel logs in breadcrumbs
        'logs' => true,

        // Capture Laravel cache events in breadcrumbs
        'cache' => true,

        // Capture Livewire components in breadcrumbs
        'livewire' => true,

        // Capture SQL queries in breadcrumbs
        'sql_queries' => true,

        // Capture bindings on SQL queries logged in breadcrumbs
        'sql_bindings' => true,

        // Capture queue job information in breadcrumbs
        'queue_info' => true,

        // Capture command information in breadcrumbs
        'command_info' => true,

        // Capture HTTP client requests information in breadcrumbs
        'http_client_requests' => true,
    ],

    'tracing' => [
        // Trace queue jobs as their own transactions
        'queue_job_transactions' => env('SENTRY_TRACE_QUEUE_ENABLED', false),

        // Capture queue jobs as spans when executed on the sync driver
        'queue_jobs' => true,

        // Capture SQL queries as spans
        'sql_queries' => true,

        // Try to find out where the SQL query originated from and add it to the query spans
        'sql_origin' => true,

        // Capture views as spans
        'views' => true,

        // Capture Livewire components as spans
        'livewire' => true,

        // Capture HTTP client requests as spans
        'http_client_requests' => true,

        // Capture Redis operations as spans (this enables Redis events in Laravel)
        'redis_commands' => env('SENTRY_TRACE_REDIS_COMMANDS', false),

        // Try to find out where the Redis command originated from and add it to the command spans
        'redis_origin' => true,

        // Indicates if the tracing integrations supplied by Sentry should be loaded
        'default_integrations' => true,

        // Indicates that requests without a matching route should be traced
        'missing_routes' => false,
    ],

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#send-default-pii
    'send_default_pii' => env('SENTRY_SEND_DEFAULT_PII', false),

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#traces-sample-rate
    'enable_tracing' => env('SENTRY_ENABLE_TRACING', false),
    'traces_sample_rate' => 0.2,

    'profiles_sample_rate' => env('SENTRY_PROFILES_SAMPLE_RATE') === null ? null : (float) env('SENTRY_PROFILES_SAMPLE_RATE'),

];
