<?php

declare(strict_types=1);

use Illuminate\Support\Str;

return [
    /*
    |--------------------------------------------------------------------------
    | Horizon Name
    |--------------------------------------------------------------------------
    |
    | This name appears in notifications and in the Horizon UI. Unique names
    | can be useful while running multiple instances of Horizon within an
    | application, allowing you to identify the Horizon you're viewing.
    |
    */

    'name' => env('HORIZON_NAME'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    |
    | This is the URI path where Horizon will be accessible from. Feel free
    | to change this path to anything you like. Note that the URI will not
    | affect the paths of its internal API that aren't exposed to users.
    |
    */

    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    |
    | This is the name of the Redis connection where Horizon will store the
    | meta information required for it to function. It includes the list
    | of supervisors, failed jobs, job metrics, and other information.
    |
    */

    'use' => 'jobs',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    |
    | This prefix will be used when storing all Horizon data in Redis. You
    | may modify the prefix when you are running multiple installations
    | of Horizon on the same server so that they don't have problems.
    |
    */

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug((string) env('APP_NAME', 'coolify'), '_').'_horizon:',
    ),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware will get attached onto each Horizon route, giving you
    | the chance to add your own middleware to this list or change any of
    | the existing middleware. Or, you can simply stick with this list.
    |
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    |
    | This option allows you to configure when the LongWaitDetected event
    | will be fired. Every connection / queue combination may have its
    | own, unique threshold (in seconds) before this event is fired.
    |
    */

    'waits' => [
        'redis:high' => 10,
        'redis:default' => 20,
        'redis:productionDeployment' => 10,
        'redis:standardDeployment' => 20,
        'redis:workerHigh' => 10,
        'redis:workerDefault' => 20,
        'redis:workerProductionDeployment' => 10,
        'redis:workerStandardDeployment' => 20,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    |
    | Here you can configure for how long (in minutes) you desire Horizon to
    | persist the recent and failed jobs. Typically, recent jobs are kept
    | for one hour while all failed jobs are stored for an entire week.
    |
    */

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    |
    | Silencing a job will instruct Horizon to not place the job in the list
    | of completed jobs within the Horizon dashboard. This setting may be
    | used to fully remove any noisy jobs from the completed jobs list.
    |
    */

    'silenced' => [
        // App\Jobs\ExampleJob::class,
    ],

    'silenced_tags' => [
        // 'notifications',
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | Here you can configure how many snapshots should be kept to display in
    | the metrics graph. This will get used in combination with Horizon's
    | `horizon:snapshot` schedule to define how long to retain metrics.
    |
    */

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    |
    | When this option is enabled, Horizon's "terminate" command will not
    | wait on all of the workers to terminate unless the --wait option
    | is provided. Fast termination can shorten deployment delay by
    | allowing a new instance of Horizon to start while the last
    | instance will continue to terminate each of its workers.
    |
    */

    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    |
    | This value describes the maximum amount of memory the Horizon master
    | supervisor may consume before it is terminated and restarted. For
    | configuring these limits on your workers, see the next section.
    |
    */

    'memory_limit' => 64,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may define the queue worker settings used by your application
    | in all environments. These supervisors and settings handle all your
    | queued jobs and will be provisioned by Horizon during deployment.
    |
    */

    'defaults' => [
        ...((bool) env('WORKER_SERVER', false) ? [] : [
            'jobs' => [
                'connection' => 'redis',
                'queue' => ['high', 'default'],
                'balance' => false,
                'autoScalingStrategy' => 'time',
                'maxTime' => 3600,
                'maxJobs' => 500,
                'memory' => 128,
                'tries' => 1,
                'timeout' => 60,
                'sleep' => 3,
                'workers-name' => 'jobs',
            ],
            'deployments' => [
                'connection' => 'redis',
                'queue' => ['productionDeployment', 'standardDeployment'],
                'balance' => false,
                'autoScalingStrategy' => 'time',
                'maxTime' => 3600,
                'maxJobs' => 300,
                'memory' => 128,
                'tries' => 1,
                'timeout' => 300,
                'sleep' => 3,
                'workers-name' => 'deployments',
            ],
        ]),
        ...((bool) env('WORKER_SERVER', false) ? [
            'worker-jobs' => [
                'connection' => 'redis',
                'queue' => ['workerHigh', 'workerDefault'],
                'balance' => false,
                'autoScalingStrategy' => 'time',
                'maxTime' => 3600,
                'maxJobs' => 500,
                'memory' => 128,
                'tries' => 1,
                'timeout' => 60,
                'sleep' => 3,
                'workers-name' => 'worker-jobs',
            ],
            'worker-deployments' => [
                'connection' => 'redis',
                'queue' => ['workerProductionDeployment', 'workerStandardDeployment'],
                'balance' => false,
                'autoScalingStrategy' => 'time',
                'maxTime' => 3600,
                'maxJobs' => 300,
                'memory' => 128,
                'tries' => 1,
                'timeout' => 300,
                'sleep' => 3,
                'workers-name' => 'worker-deployments',
            ],
        ] : []),
    ],

    'environments' => [
        '*' => [
            ...((bool) env('WORKER_SERVER', false) ? [] : [
                'jobs' => [
                    'minProcesses' => env('HORIZON_JOBS_MIN_PROCESSES', 1),
                    'maxProcesses' => env('HORIZON_JOBS_MAX_PROCESSES', 4),
                    'balanceMaxShift' => env('HORIZON_JOBS_BALANCE_MAX_SHIFT', 1),
                    'balanceCooldown' => env('HORIZON_JOBS_BALANCE_COOLDOWN', 2),
                ],
                'deployments' => [
                    'minProcesses' => env('HORIZON_DEPLOYMENTS_MIN_PROCESSES', 1),
                    'maxProcesses' => env('HORIZON_DEPLOYMENTS_MAX_PROCESSES', 2),
                    'balanceMaxShift' => env('HORIZON_DEPLOYMENTS_BALANCE_MAX_SHIFT', 1),
                    'balanceCooldown' => env('HORIZON_DEPLOYMENTS_BALANCE_COOLDOWN', 2),
                ],
            ]),
            ...((bool) env('WORKER_SERVER', false) ? [
                'worker-jobs' => [
                    'minProcesses' => env('HORIZON_JOBS_MIN_PROCESSES', 1),
                    'maxProcesses' => env('HORIZON_JOBS_MAX_PROCESSES', 6),
                    'balanceMaxShift' => env('HORIZON_JOBS_BALANCE_MAX_SHIFT', 2),
                    'balanceCooldown' => env('HORIZON_JOBS_BALANCE_COOLDOWN', 1),
                ],
                'worker-deployments' => [
                    'minProcesses' => env('HORIZON_DEPLOYMENTS_MIN_PROCESSES', 1),
                    'maxProcesses' => env('HORIZON_DEPLOYMENTS_MAX_PROCESSES', 4),
                    'balanceMaxShift' => env('HORIZON_DEPLOYMENTS_BALANCE_MAX_SHIFT', 2),
                    'balanceCooldown' => env('HORIZON_DEPLOYMENTS_BALANCE_COOLDOWN', 1),
                ],
            ] : []),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | File Watcher Configuration
    |--------------------------------------------------------------------------
    |
    | The following list of directories and files will be watched when using
    | the `horizon:listen` command. Whenever any directories or files are
    | changed, Horizon will automatically restart to apply all changes.
    |
    */

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        'composer.json',
        '.env',
    ],
];
