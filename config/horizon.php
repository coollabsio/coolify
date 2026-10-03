<?php

use App\Models\ScheduledVolumeBackup;
use Illuminate\Support\Str;

/*
| Cloud mode is read from SELF_HOSTED directly instead of isCloud(): isCloud()
| reads the config repository, which is still being built while this file loads.
*/
$isCloud = ! env('SELF_HOSTED', true);

$workerOptions = [
    'connection' => 'redis',
    'maxTime' => env('HORIZON_MAX_TIME', 0),
    'maxJobs' => 400,
    'memory' => 128,
    'tries' => 1,
    'nice' => 0,
    'sleep' => 3,
    'timeout' => min(
        max((int) env('HORIZON_TIMEOUT', 39600), ScheduledVolumeBackup::DEFAULT_TIMEOUT + 600),
        85800,
    ),
];

$selfHostedSupervisor = $workerOptions + [
    'balance' => env('HORIZON_BALANCE', 'false'),
    'queue' => env('HORIZON_QUEUES', 'high,default'),
    'autoScalingStrategy' => 'size',
    'minProcesses' => env('HORIZON_MIN_PROCESSES', 1),
    'maxProcesses' => env('HORIZON_MAX_PROCESSES', 4),
    'balanceMaxShift' => env('HORIZON_BALANCE_MAX_SHIFT', 1),
    'balanceCooldown' => env('HORIZON_BALANCE_COOLDOWN', 1),
];

/*
| Coolify Cloud: one fixed-size pool per queue, so a busy queue cannot starve
| the others. Process counts apply to each node. Invalid values (not a
| positive integer) fall back to the default.
|
| The `maintenance` pool (see maintenance_queue()) runs slow remote Docker
| cleanups. It is small on purpose: it bounds how many cleanups run at once
| per node, and it comes on top of the other pools, so cleanups never take
| deployment, cron or high workers.
*/
$cloudSupervisors = [];

foreach ([
    'deployments' => ['HORIZON_DEPLOYMENTS_PROCESSES', 60],
    'crons' => ['HORIZON_CRONS_PROCESSES', 60],
    'high' => ['HORIZON_HIGH_PROCESSES', 60],
    'default' => ['HORIZON_DEFAULT_PROCESSES', 40],
    'maintenance' => ['HORIZON_MAINTENANCE_PROCESSES', 10],
] as $queue => [$processesEnv, $defaultProcesses]) {
    $processes = filter_var(env($processesEnv), FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'default' => $defaultProcesses],
    ]);

    $cloudSupervisors[$queue] = $workerOptions + [
        'queue' => $queue,
        'balance' => false,
        'minProcesses' => $processes,
        'maxProcesses' => $processes,
    ];
}

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where Horizon will be accessible from. If this
    | setting is null, Horizon will reside under the same domain as the
    | application. Otherwise, this value will serve as the subdomain.
    |
    */

    'domain' => env('HORIZON_DOMAIN'),

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
    | Horizon Allowed Emails
    |--------------------------------------------------------------------------
    |
    | A comma-separated list of email addresses that may access the Horizon
    | dashboard in addition to the root user.
    |
    */

    'allowed_emails' => env('HORIZON_ALLOWED_EMAILS', ''),

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

    'use' => 'default',

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
        Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'
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
        'redis:default' => 60,
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
        'recent' => 10,
        'pending' => 30,
        'completed' => 10,
        'recent_failed' => 1440,
        'failed' => 1440,
        'monitored' => 1440,
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
    | "defaults" stays empty: Horizon merges every default supervisor into
    | every environment, so each environment defines complete options.
    |
    */

    'defaults' => [],

    /*
    | Worker timeout shared by every supervisor. Jobs that must finish before
    | the worker kills them (for example database imports) read this value.
    */
    'worker_timeout' => $workerOptions['timeout'],

    'environments' => [
        'production' => $isCloud ? $cloudSupervisors : ['s6' => $selfHostedSupervisor],
        'local' => ['s6' => $selfHostedSupervisor],
    ],
];
