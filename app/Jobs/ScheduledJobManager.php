<?php

namespace App\Jobs;

use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledTask;
use App\Models\Server;
use App\Models\Team;
use Cron\CronExpression;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class ScheduledJobManager implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The time when this job execution started.
     * Used to ensure all scheduled items are evaluated against the same point in time.
     */
    private ?Carbon $executionTime = null;

    private int $dispatchedCount = 0;

    private int $skippedCount = 0;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        $this->onQueue($this->determineQueue());
    }

    private function determineQueue(): string
    {
        $preferredQueue = 'crons';
        $fallbackQueue = 'high';

        $configuredQueues = explode(',', env('HORIZON_QUEUES', 'high,default'));

        return in_array($preferredQueue, $configuredQueues) ? $preferredQueue : $fallbackQueue;
    }

    /**
     * Get the middleware the job should pass through.
     */
    public function middleware(): array
    {
        // Self-healing: clear any stale lock before WithoutOverlapping tries to acquire it.
        // Stale locks (TTL = -1) can occur during upgrades, Redis restarts, or edge cases.
        // @see https://github.com/coollabsio/coolify/issues/8327
        self::clearStaleLockIfPresent();

        return [
            (new WithoutOverlapping('scheduled-job-manager'))
                ->expireAfter(90)   // Lock expires after 90s to handle high-load environments with many tasks
                ->dontRelease(),    // Don't re-queue on lock conflict
        ];
    }

    /**
     * Clear a stale WithoutOverlapping lock if it has no TTL (TTL = -1).
     *
     * This provides continuous self-healing since it runs every time the job is dispatched.
     * Stale locks permanently block all scheduled job executions with no user-visible error.
     */
    private static function clearStaleLockIfPresent(): void
    {
        try {
            $cachePrefix = config('cache.prefix', '');
            $lockKey = $cachePrefix.'laravel-queue-overlap:'.self::class.':scheduled-job-manager';

            $ttl = Redis::connection('default')->ttl($lockKey);

            if ($ttl === -1) {
                Redis::connection('default')->del($lockKey);
                Log::channel('scheduled')->warning('Cleared stale ScheduledJobManager lock', [
                    'lock_key' => $lockKey,
                ]);
            }
        } catch (\Throwable $e) {
            // Never let lock cleanup failure prevent the job from running
            Log::channel('scheduled-errors')->error('Failed to check/clear stale lock', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function handle(): void
    {
        // Freeze the execution time at the start of the job
        $this->executionTime = Carbon::now();
        $this->dispatchedCount = 0;
        $this->skippedCount = 0;

        Log::channel('scheduled')->info('ScheduledJobManager started', [
            'execution_time' => $this->executionTime->toIso8601String(),
        ]);

        // Process backups - don't let failures stop task processing
        try {
            $this->processScheduledBackups();
        } catch (\Exception $e) {
            Log::channel('scheduled-errors')->error('Failed to process scheduled backups', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        // Process tasks - don't let failures stop the job manager
        try {
            $this->processScheduledTasks();
        } catch (\Exception $e) {
            Log::channel('scheduled-errors')->error('Failed to process scheduled tasks', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        // Process Docker cleanups - don't let failures stop the job manager
        try {
            $this->processDockerCleanups();
        } catch (\Exception $e) {
            Log::channel('scheduled-errors')->error('Failed to process docker cleanups', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        Log::channel('scheduled')->info('ScheduledJobManager completed', [
            'execution_time' => $this->executionTime->toIso8601String(),
            'duration_ms' => $this->executionTime->diffInMilliseconds(Carbon::now()),
            'dispatched' => $this->dispatchedCount,
            'skipped' => $this->skippedCount,
        ]);

        // Write heartbeat so the UI can detect when the scheduler has stopped
        try {
            Cache::put('scheduled-job-manager:heartbeat', now()->toIso8601String(), 300);
        } catch (\Throwable) {
            // Non-critical; don't let heartbeat failure affect the job
        }
    }

    private function processScheduledBackups(): void
    {
        $backups = ScheduledDatabaseBackup::with(['database'])
            ->where('enabled', true)
            ->get();

        foreach ($backups as $backup) {
            try {
                $skipReason = $this->getBackupSkipReason($backup);
                if ($skipReason !== null) {
                    $this->skippedCount++;
                    $this->logSkip('backup', $skipReason, [
                        'backup_id' => $backup->id,
                        'database_id' => $backup->database_id,
                        'database_type' => $backup->database_type,
                        'team_id' => $backup->team_id ?? null,
                    ]);

                    continue;
                }

                $server = $backup->server();
                $serverTimezone = data_get($server->settings, 'server_timezone', config('app.timezone'));

                if (validate_timezone($serverTimezone) === false) {
                    $serverTimezone = config('app.timezone');
                }

                $frequency = $backup->frequency;
                if (isset(VALID_CRON_STRINGS[$frequency])) {
                    $frequency = VALID_CRON_STRINGS[$frequency];
                }

                if ($this->shouldRunNow($frequency, $serverTimezone)) {
                    DatabaseBackupJob::dispatch($backup);
                    $this->dispatchedCount++;
                    Log::channel('scheduled')->info('Backup dispatched', [
                        'backup_id' => $backup->id,
                        'database_id' => $backup->database_id,
                        'database_type' => $backup->database_type,
                        'team_id' => $backup->team_id ?? null,
                        'server_id' => $server->id,
                    ]);
                }
            } catch (\Exception $e) {
                Log::channel('scheduled-errors')->error('Error processing backup', [
                    'backup_id' => $backup->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function processScheduledTasks(): void
    {
        $tasks = ScheduledTask::with(['service', 'application'])
            ->where('enabled', true)
            ->get();

        foreach ($tasks as $task) {
            try {
                $skipReason = $this->getTaskSkipReason($task);
                if ($skipReason !== null) {
                    $this->skippedCount++;
                    $this->logSkip('task', $skipReason, [
                        'task_id' => $task->id,
                        'task_name' => $task->name,
                        'team_id' => $task->server()?->team_id,
                    ]);

                    continue;
                }

                $server = $task->server();
                $serverTimezone = data_get($server->settings, 'server_timezone', config('app.timezone'));

                if (validate_timezone($serverTimezone) === false) {
                    $serverTimezone = config('app.timezone');
                }

                $frequency = $task->frequency;
                if (isset(VALID_CRON_STRINGS[$frequency])) {
                    $frequency = VALID_CRON_STRINGS[$frequency];
                }

                if ($this->shouldRunNow($frequency, $serverTimezone)) {
                    ScheduledTaskJob::dispatch($task);
                    $this->dispatchedCount++;
                    Log::channel('scheduled')->info('Task dispatched', [
                        'task_id' => $task->id,
                        'task_name' => $task->name,
                        'team_id' => $server->team_id,
                        'server_id' => $server->id,
                    ]);
                }
            } catch (\Exception $e) {
                Log::channel('scheduled-errors')->error('Error processing task', [
                    'task_id' => $task->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function getBackupSkipReason(ScheduledDatabaseBackup $backup): ?string
    {
        if (blank(data_get($backup, 'database'))) {
            $backup->delete();

            return 'database_deleted';
        }

        $server = $backup->server();
        if (blank($server)) {
            $backup->delete();

            return 'server_deleted';
        }

        if ($server->isFunctional() === false) {
            return 'server_not_functional';
        }

        if (isCloud() && data_get($server->team->subscription, 'stripe_invoice_paid', false) === false && $server->team->id !== 0) {
            return 'subscription_unpaid';
        }

        return null;
    }

    private function getTaskSkipReason(ScheduledTask $task): ?string
    {
        $service = $task->service;
        $application = $task->application;

        $server = $task->server();
        if (blank($server)) {
            $task->delete();

            return 'server_deleted';
        }

        if ($server->isFunctional() === false) {
            return 'server_not_functional';
        }

        if (isCloud() && data_get($server->team->subscription, 'stripe_invoice_paid', false) === false && $server->team->id !== 0) {
            return 'subscription_unpaid';
        }

        if (! $service && ! $application) {
            $task->delete();

            return 'resource_deleted';
        }

        if ($application && str($application->status)->contains('running') === false) {
            return 'application_not_running';
        }

        if ($service && str($service->status)->contains('running') === false) {
            return 'service_not_running';
        }

        return null;
    }

    private function shouldRunNow(string $frequency, string $timezone): bool
    {
        $cron = new CronExpression($frequency);

        // Use the frozen execution time, not the current time
        // Fallback to current time if execution time is not set (shouldn't happen)
        $baseTime = $this->executionTime ?? Carbon::now();
        $executionTime = $baseTime->copy()->setTimezone($timezone);

        return $cron->isDue($executionTime);
    }

    private function processDockerCleanups(): void
    {
        // Get all servers that need cleanup checks
        $servers = $this->getServersForCleanup();

        foreach ($servers as $server) {
            try {
                $skipReason = $this->getDockerCleanupSkipReason($server);
                if ($skipReason !== null) {
                    $this->skippedCount++;
                    $this->logSkip('docker_cleanup', $skipReason, [
                        'server_id' => $server->id,
                        'server_name' => $server->name,
                        'team_id' => $server->team_id,
                    ]);

                    continue;
                }

                $serverTimezone = data_get($server->settings, 'server_timezone', config('app.timezone'));
                if (validate_timezone($serverTimezone) === false) {
                    $serverTimezone = config('app.timezone');
                }

                $frequency = data_get($server->settings, 'docker_cleanup_frequency', '0 * * * *');
                if (isset(VALID_CRON_STRINGS[$frequency])) {
                    $frequency = VALID_CRON_STRINGS[$frequency];
                }

                // Use the frozen execution time for consistent evaluation
                if ($this->shouldRunNow($frequency, $serverTimezone)) {
                    DockerCleanupJob::dispatch(
                        $server,
                        false,
                        $server->settings->delete_unused_volumes,
                        $server->settings->delete_unused_networks
                    );
                    $this->dispatchedCount++;
                    Log::channel('scheduled')->info('Docker cleanup dispatched', [
                        'server_id' => $server->id,
                        'server_name' => $server->name,
                        'team_id' => $server->team_id,
                    ]);
                }
            } catch (\Exception $e) {
                Log::channel('scheduled-errors')->error('Error processing docker cleanup', [
                    'server_id' => $server->id,
                    'server_name' => $server->name,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function getServersForCleanup(): Collection
    {
        $query = Server::with('settings')
            ->where('ip', '!=', '1.2.3.4');

        if (isCloud()) {
            $servers = $query->whereRelation('team.subscription', 'stripe_invoice_paid', true)->get();
            $own = Team::find(0)->servers()->with('settings')->get();

            return $servers->merge($own);
        }

        return $query->get();
    }

    private function getDockerCleanupSkipReason(Server $server): ?string
    {
        if (! $server->isFunctional()) {
            return 'server_not_functional';
        }

        // In cloud, check subscription status (except team 0)
        if (isCloud() && $server->team_id !== 0) {
            if (data_get($server->team->subscription, 'stripe_invoice_paid', false) === false) {
                return 'subscription_unpaid';
            }
        }

        return null;
    }

    private function logSkip(string $type, string $reason, array $context = []): void
    {
        Log::channel('scheduled')->info(ucfirst(str_replace('_', ' ', $type)).' skipped', array_merge([
            'type' => $type,
            'skip_reason' => $reason,
            'execution_time' => $this->executionTime?->toIso8601String(),
        ], $context));
    }
}
