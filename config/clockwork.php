<?php

return [

	/*
	|------------------------------------------------------------------------------------------------------------------
	| Enable Clockwork
	|------------------------------------------------------------------------------------------------------------------
	|
	| Clockwork is enabled by default only when your application is in debug mode. Here you can explicitly enable or
	| disable Clockwork. When disabled, no data is collected and the api and web ui are inactive.
	| Unless explicitly enabled, Clockwork only runs on localhost, *.local, *.test and *.wip domains.
	|
	*/

	'enable' => env('CLOCKWORK_ENABLE', null),

	/*
	|------------------------------------------------------------------------------------------------------------------
	| Features
	|------------------------------------------------------------------------------------------------------------------
	|
	| You can enable or disable various Clockwork features here. Some features have additional settings (eg. slow query
	| threshold for database queries).
	|
	*/

	'features' => [

		// Cache usage stats and cache queries including results
		'cache' => [
			'enabled' => env('CLOCKWORK_CACHE_ENABLED', true),

			// Collect cache queries
			'collect_queries' => env('CLOCKWORK_CACHE_QUERIES', true),

			// Collect values from cache queries (high performance impact with a very high number of queries)
			'collect_values' => env('CLOCKWORK_CACHE_COLLECT_VALUES', false)
		],

		// Database usage stats and queries
		'database' => [
			'enabled' => env('CLOCKWORK_DATABASE_ENABLED', true),

			// Collect database queries (high performance impact with a very high number of queries)
			'collect_queries' => env('CLOCKWORK_DATABASE_COLLECT_QUERIES', true),

			// Collect details of models updates (high performance impact with a lot of model updates)
			'collect_models_actions' => env('CLOCKWORK_DATABASE_COLLECT_MODELS_ACTIONS', true),

			// Collect details of retrieved models (very high performance impact with a lot of models retrieved)
			'collect_models_retrieved' => env('CLOCKWORK_DATABASE_COLLECT_MODELS_RETRIEVED', false),

			// Query execution time threshold in milliseconds after which the query will be marked as slow
			'slow_threshold' => env('CLOCKWORK_DATABASE_SLOW_THRESHOLD'),

			// Collect only slow database queries
			'slow_only' => env('CLOCKWORK_DATABASE_SLOW_ONLY', false),

			// Detect and report duplicate queries
			'detect_duplicate_queries' => env('CLOCKWORK_DATABASE_DETECT_DUPLICATE_QUERIES', false)
		],

		// Dispatched events
		'events' => [
			'enabled' => env('CLOCKWORK_EVENTS_ENABLED', true),

			// Ignored events (framework events are ignored by default)
			'ignored_events' => [
				// App\Events\UserRegistered::class,
				// 'user.registered'
			],
		],

		// Laravel log (you can still log directly to Clockwork with laravel log disabled)
		'log' => [
			'enabled' => env('CLOCKWORK_LOG_ENABLED', true)
		],

		// Sent notifications
		'notifications' => [
			'enabled' => env('CLOCKWORK_NOTIFICATIONS_ENABLED', true),
		],

		// Performance metrics
		'performance' => [
			// Allow collecting of client metrics. Requires separate clockwork-browser npm package.
			'client_metrics' => env('CLOCKWORK_PERFORMANCE_CLIENT_METRICS', true)
		],

		// Dispatched queue jobs
		'queue' => [
			'enabled' => env('CLOCKWORK_QUEUE_ENABLED', true)
		],

		// Redis commands
		'redis' => [
			'enabled' => env('CLOCKWORK_REDIS_ENABLED', true)
		],

		// Routes list
		'routes' => [
			'enabled' => env('CLOCKWORK_ROUTES_ENABLED', false),

			// Collect only routes from particular namespaces (only application routes by default)
			'only_namespaces' => [ 'App' ]
		],

		// Rendered views
		'views' => [
			'enabled' => env('CLOCKWORK_VIEWS_ENABLED', true),

			// Collect views including view data (high performance impact with a high number of views)
			'collect_data' => env('CLOCKWORK_VIEWS_COLLECT_DATA', false),

			// Use Twig profiler instead of Laravel events for apps using laravel-twigbridge (more precise, but does
			// not support collecting view data)
			'use_twig_profiler' => env('CLOCKWORK_VIEWS_USE_TWIG_PROFILER', false)
		]

	],

	/*
	|------------------------------------------------------------------------------------------------------------------
	| Enable web UI
	|------------------------------------------------------------------------------------------------------------------
	|
	| Clockwork comes with a web UI accessible via http://your.app/clockwork. Here you can enable or disable this
	| feature. You can also set a custom path for the web UI.
	|
	*/

	'web' => env('CLOCKWORK_WEB', true),

	/*
	|------------------------------------------------------------------------------------------------------------------
	| Enable toolbar
	|------------------------------------------------------------------------------------------------------------------
	|
	| Clockwork can show a toolbar with basic metrics on all responses. Here you can enable or disable this feature.
	| Requires a separate clockwork-browser npm library.
	| For installation instructions see https://underground.works/clockwork/#docs-viewing-data
	|
	*/

	'toolbar' => env('CLOCKWORK_TOOLBAR', true),

	/*
	|------------------------------------------------------------------------------------------------------------------
	| HTTP requests collection
	|------------------------------------------------------------------------------------------------------------------
	|
	| Clockwork collects data about HTTP requests to your app. Here you can choose which requests should be collected.
	|
	*/

	'requests' => [
		// With on-demand mode enabled, Clockwork will only profile requests when the browser extension is open or you
		// manually pass a "clockwork-profile" cookie or get/post data key.
		// Optionally you can specify a "secret" that has to be passed as the value to enable profiling.
		'on_demand' => env('CLOCKWORK_REQUESTS_ON_DEMAND', false),

		// Collect only errors (requests with HTTP 4xx and 5xx responses)
		'errors_only' => env('CLOCKWORK_REQUESTS_ERRORS_ONLY', false),

		// Response time threshold in milliseconds after which the request will be marked as slow
		'slow_threshold' => env('CLOCKWORK_REQUESTS_SLOW_THRESHOLD'),

		// Collect only slow requests
		'slow_only' => env('CLOCKWORK_REQUESTS_SLOW_ONLY', false),

		// Sample the collected requests (e.g. set to 100 to collect only 1 in 100 requests)
		'sample' => env('CLOCKWORK_REQUESTS_SAMPLE', false),

		// List of URIs that should not be collected
		'except' => [
			'/horizon/.*', // Laravel Horizon requests
			'/telescope/.*', // Laravel Telescope requests
			'/_tt/.*', // Laravel Telescope toolbar
			'/_debugbar/.*', // Laravel DebugBar requests
		],

		// List of URIs that should be collected, any other URI will not be collected if not empty
		'only' => [
			// '/api/.*'
		],

		// Don't collect OPTIONS requests, mostly used in the CSRF pre-flight requests and are rarely of interest
		'except_preflight' => env('CLOCKWORK_REQUESTS_EXCEPT_PREFLIGHT', true)
	],

	/*
	|------------------------------------------------------------------------------------------------------------------
	| Artisan commands collection
	|------------------------------------------------------------------------------------------------------------------
	|
	| Clockwork can collect data about executed artisan commands. Here you can enable and configure which commands
	| should be collected.
	|
	*/

	'artisan' => [
		// Enable or disable collection of executed Artisan commands
		'collect' => env('CLOCKWORK_ARTISAN_COLLECT', false),

		// List of commands that should not be collected (built-in commands are not collected by default)
		'except' => [
			// 'inspire'
		],

		// List of commands that should be collected, any other command will not be collected if not empty
		'only' => [
			// 'inspire'
		],

		// Enable or disable collection of command output
		'collect_output' => env('CLOCKWORK_ARTISAN_COLLECT_OUTPUT', false),

		// Enable or disable collection of built-in Laravel commands
		'except_laravel_commands' => env('CLOCKWORK_ARTISAN_EXCEPT_LARAVEL_COMMANDS', true)
	],

	/*
	|------------------------------------------------------------------------------------------------------------------
	| Queue jobs collection
	|------------------------------------------------------------------------------------------------------------------
	|
	| Clockwork can collect data about executed queue jobs. Here you can enable and configure which queue jobs should
	| be collected.
	|
	*/

	'queue' => [
		// Enable or disable collection of executed queue jobs
		'collect' => env('CLOCKWORK_QUEUE_COLLECT', false),

		// List of queue jobs that should not be collected
		'except' => [
			// App\Jobs\ExpensiveJob::class
		],

		// List of queue jobs that should be collected, any other queue job will not be collected if not empty
		'only' => [
			// App\Jobs\BuggyJob::class
		]
	],

	/*
	|------------------------------------------------------------------------------------------------------------------
	| Tests collection
	|------------------------------------------------------------------------------------------------------------------
	|
	| Clockwork can collect data about executed tests. Here you can enable and configure which tests should be
	| collected.
	|
	*/

	'tests' => [
		// Enable or disable collection of ran tests
		'collect' => env('CLOCKWORK_TESTS_COLLECT', false),

		// List of tests that should not be collected
		'except' => [
			// Tests\Unit\ExampleTest::class
		]
	],

	/*
	|------------------------------------------------------------------------------------------------------------------
	| Enable data collection when Clockwork is disabled
	|------------------------------------------------------------------------------------------------------------------
	|
	| You can enable this setting to collect data even when Clockwork is disabled, e.g. for future analysis.
	|
	*/

	'collect_data_always' => env('CLOCKWORK_COLLECT_DATA_ALWAYS', false),

	/*
	|------------------------------------------------------------------------------------------------------------------
	| Metadata storage
	|------------------------------------------------------------------------------------------------------------------
	|
	| Configure how is the metadata collected by Clockwork stored. Three options are available:
	|   - files - A simple fast storage implementation storing data in one-per-request files.
	|   - sql - Stores requests in a sql database. Supports MySQL, PostgreSQL and SQLite. Requires PDO.
	|   - redis - Stores requests in redis. Requires phpredis.
	*/

	'storage' => env('CLOCKWORK_STORAGE', 'files'),

	// Path where the Clockwork metadata is stored
	'storage_files_path' => env('CLOCKWORK_STORAGE_FILES_PATH', storage_path('clockwork')),

	// Compress the metadata files using gzip, trading a little bit of performance for lower disk usage
	'storage_files_compress' => env('CLOCKWORK_STORAGE_FILES_COMPRESS', false),

	// SQL database to use, can be a name of database configured in database.php or a path to a SQLite file
	'storage_sql_database' => env('CLOCKWORK_STORAGE_SQL_DATABASE', storage_path('clockwork.sqlite')),

	// SQL table name to use, the table is automatically created and updated when needed
	'storage_sql_table' => env('CLOCKWORK_STORAGE_SQL_TABLE', 'clockwork'),

	// Redis connection, name of redis connection or cluster configured in database.php
	'storage_redis' => env('CLOCKWORK_STORAGE_REDIS', 'default'),

	// Redis prefix for Clockwork keys ("clockwork" if not set)
	'storage_redis_prefix' => env('CLOCKWORK_STORAGE_REDIS_PREFIX', 'clockwork'),

	// Maximum lifetime of collected metadata in minutes, older requests will automatically be deleted, false to disable
	'storage_expiration' => env('CLOCKWORK_STORAGE_EXPIRATION', 60 * 24 * 7),

	/*
	|------------------------------------------------------------------------------------------------------------------
	| Authentication
	|------------------------------------------------------------------------------------------------------------------
	|
	| Clockwork can be configured to require authentication before allowing access to the collected data. This might be
	| useful when the application is publicly accessible. Setting to true will enable a simple authentication with a
	| pre-configured password. You can also pass a class name of a custom implementation.
	|
	*/

	'authentication' => env('CLOCKWORK_AUTHENTICATION', false),

	// Password for the simple authentication
	'authentication_password' => env('CLOCKWORK_AUTHENTICATION_PASSWORD', 'VerySecretPassword'),

	/*
	|------------------------------------------------------------------------------------------------------------------
	| Stack traces collection
	|------------------------------------------------------------------------------------------------------------------
	|
	| Clockwork can collect stack traces for log messages and certain data like database queries. Here you can set
	| whether to collect stack traces, limit the number of collected frames and set further configuration. Collecting
	| long stack traces considerably increases metadata size.
	|
	*/

	'stack_traces' => [
		// Enable or disable collecting of stack traces
		'enabled' => env('CLOCKWORK_STACK_TRACES_ENABLED', true),

		// Limit the number of frames to be collected
		'limit' => env('CLOCKWORK_STACK_TRACES_LIMIT', 10),

		// List of vendor names to skip when determining caller, common vendors are automatically added
		'skip_vendors' => [
			// 'phpunit'
		],

		// List of namespaces to skip when determining caller
		'skip_namespaces' => [
			// 'Laravel'
		],

		// List of class names to skip when determining caller
		'skip_classes' => [
			// App\CustomLog::class
		]

	],

	/*
	|------------------------------------------------------------------------------------------------------------------
	| Serialization
	|------------------------------------------------------------------------------------------------------------------
	|
	| Clockwork serializes the collected data to json for storage and transfer. Here you can configure certain aspects
	| of serialization. Serialization has a large effect on the cpu time and memory usage.
	|
	*/

	// Maximum depth of serialized multi-level arrays and objects
	'serialization_depth' => env('CLOCKWORK_SERIALIZATION_DEPTH', 10),

	// A list of classes that will never be serialized (e.g. a common service container class)
	'serialization_blackbox' => [
		\Illuminate\Container\Container::class,
		\Illuminate\Foundation\Application::class,
		\Laravel\Lumen\Application::class
	],

	/*
	|------------------------------------------------------------------------------------------------------------------
	| Register helpers
	|------------------------------------------------------------------------------------------------------------------
	|
	| Clockwork comes with a "clock" global helper function. You can use this helper to quickly log something and to
	| access the Clockwork instance.
	|
	*/

	'register_helpers' => env('CLOCKWORK_REGISTER_HELPERS', true),

	/*
	|------------------------------------------------------------------------------------------------------------------
	| Send headers for AJAX request
	|------------------------------------------------------------------------------------------------------------------
	|
	| When trying to collect data, the AJAX method can sometimes fail if it is missing required headers. For example, an
	| API might require a version number using Accept headers to route the HTTP request to the correct codebase.
	|
	*/

	'headers' => [
		// 'Accept' => 'application/vnd.com.whatever.v1+json',
	],

	/*
	|------------------------------------------------------------------------------------------------------------------
	| Server timing
	|------------------------------------------------------------------------------------------------------------------
	|
	| Clockwork supports the W3C Server Timing specification, which allows for collecting a simple performance metrics
	| in a cross-browser way. E.g. in Chrome, your app, database and timeline event timings will be shown in the Dev
	| Tools network tab. This setting specifies the max number of timeline events that will be sent. Setting to false
	| will disable the feature.
	|
	*/

	'server_timing' => env('CLOCKWORK_SERVER_TIMING', 10)

];
