<?php

namespace App\Jobs;

use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledTask;
use App\Models\Server;
use App\Models\Team;
use Cron\CronExpression;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class ScheduledJobManager implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const CHUNK_SIZE = 100;

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
        $this->onQueue(crons_queue());
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

        // Process scheduled backups and tasks together so neither type starves the other.
        try {
            $this->processScheduledBackupsAndTasks();
        } catch (\Exception $e) {
            Log::channel('scheduled-errors')->error('Failed to process scheduled backups and tasks', [
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

    private function processScheduledBackupsAndTasks(): void
    {
        $lastBackupId = 0;
        $lastTaskId = 0;

        do {
            $backups = $this->scheduledBackupQuery($lastBackupId)->get();
            $tasks = $this->scheduledTaskQuery($lastTaskId)->get();

            if ($backups->isNotEmpty()) {
                $lastBackupId = $backups->last()->id;
            }

            if ($tasks->isNotEmpty()) {
                $lastTaskId = $tasks->last()->id;
            }

            $this->processInterleavedDueSchedules(
                $this->dueScheduledBackups($backups),
                $this->dueScheduledTasks($tasks),
            );
        } while ($backups->isNotEmpty() || $tasks->isNotEmpty());
    }

    /**
     * @param  array<int, array{backup: ScheduledDatabaseBackup, server: Server}>  $dueBackups
     * @param  array<int, array{task: ScheduledTask, server: Server}>  $dueTasks
     */
    private function processInterleavedDueSchedules(array $dueBackups, array $dueTasks): void
    {
        $maxCount = max(count($dueBackups), count($dueTasks));

        for ($index = 0; $index < $maxCount; $index++) {
            if (isset($dueBackups[$index])) {
                $this->processScheduledBackup($dueBackups[$index]['backup'], $dueBackups[$index]['server']);
            }

            if (isset($dueTasks[$index])) {
                $this->processScheduledTask($dueTasks[$index]['task'], $dueTasks[$index]['server']);
            }
        }
    }

    private function scheduledBackupQuery(int $lastBackupId): Builder
    {
        return ScheduledDatabaseBackup::with(['database', 'team.subscription'])
            ->where('enabled', true)
            ->where('id', '>', $lastBackupId)
            ->orderBy('id')
            ->limit(self::CHUNK_SIZE);
    }

    private function scheduledTaskQuery(int $lastTaskId): Builder
    {
        return ScheduledTask::with([
            'service.destination.server.settings',
            'service.destination.server.team.subscription',
            'application.destination.server.settings',
            'application.destination.server.team.subscription',
        ])
            ->where('enabled', true)
            ->where('id', '>', $lastTaskId)
            ->orderBy('id')
            ->limit(self::CHUNK_SIZE);
    }

    /**
     * @param  iterable<ScheduledDatabaseBackup>  $backups
     * @return array<int, array{backup: ScheduledDatabaseBackup, server: Server}>
     */
    private function dueScheduledBackups(iterable $backups): array
    {
        $dueBackups = [];

        foreach ($backups as $backup) {
            try {
                $server = $backup->server();

                if (blank(data_get($backup, 'database')) || blank($server)) {
                    $this->processScheduledBackup($backup, $server);

                    continue;
                }

                if ($this->isDueCandidateBeforeExpensiveChecks($backup->frequency, $server, "scheduled-backup:{$backup->id}")) {
                    $dueBackups[] = [
                        'backup' => $backup,
                        'server' => $server,
                    ];
                }
            } catch (\Exception $e) {
                Log::channel('scheduled-errors')->error('Error prechecking backup', [
                    'backup_id' => $backup->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $dueBackups;
    }

    /**
     * @param  iterable<ScheduledTask>  $tasks
     * @return array<int, array{task: ScheduledTask, server: Server}>
     */
    private function dueScheduledTasks(iterable $tasks): array
    {
        $dueTasks = [];

        foreach ($tasks as $task) {
            try {
                $server = $task->server();

                if (blank($server) || (! $task->service && ! $task->application)) {
                    $this->processScheduledTask($task, $server);

                    continue;
                }

                if ($this->isDueCandidateBeforeExpensiveChecks($task->frequency, $server, "scheduled-task:{$task->id}")) {
                    $dueTasks[] = [
                        'task' => $task,
                        'server' => $server,
                    ];
                }
            } catch (\Exception $e) {
                Log::channel('scheduled-errors')->error('Error prechecking task', [
                    'task_id' => $task->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $dueTasks;
    }

    private function processScheduledBackup(ScheduledDatabaseBackup $backup, ?Server $precheckedServer = null): void
    {
        try {
            $server = $precheckedServer ?? $backup->server();
            $skipReason = $this->getBackupSkipReason($backup, $server);
            if ($skipReason !== null) {
                $this->skippedCount++;
                $this->logBackupSkip($backup, $skipReason);

                return;
            }

            if ($this->shouldDispatch($backup->frequency, $server, "scheduled-backup:{$backup->id}")) {
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

    private function processScheduledTask(ScheduledTask $task, ?Server $precheckedServer = null): void
    {
        try {
            $server = $precheckedServer ?? $task->server();
            $criticalSkip = $this->getTaskCriticalSkipReason($task, $server);
            if ($criticalSkip !== null) {
                $this->skippedCount++;
                $this->logTaskSkip($task, $criticalSkip, $server);

                return;
            }

            if (! $this->shouldDispatch($task->frequency, $server, "scheduled-task:{$task->id}")) {
                return;
            }

            $runtimeSkip = $this->getTaskRuntimeSkipReason($task);
            if ($runtimeSkip !== null) {
                $this->skippedCount++;
                $this->logTaskSkip($task, $runtimeSkip, $server);

                return;
            }

            ScheduledTaskJob::dispatch($task);
            $this->dispatchedCount++;
            Log::channel('scheduled')->info('Task dispatched', [
                'task_id' => $task->id,
                'task_name' => $task->name,
                'team_id' => $server->team_id,
                'server_id' => $server->id,
            ]);
        } catch (\Exception $e) {
            Log::channel('scheduled-errors')->error('Error processing task', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function getBackupSkipReason(ScheduledDatabaseBackup $backup, ?Server $server): ?string
    {
        if (blank(data_get($backup, 'database'))) {
            $backup->delete();

            return 'database_deleted';
        }

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

    private function getTaskCriticalSkipReason(ScheduledTask $task, ?Server $server): ?string
    {
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

        if (! $task->service && ! $task->application) {
            $task->delete();

            return 'resource_deleted';
        }

        return null;
    }

    private function getTaskRuntimeSkipReason(ScheduledTask $task): ?string
    {
        if ($task->application && str($task->application->status)->contains('running') === false) {
            return 'application_not_running';
        }

        if ($task->service && str($task->service->status)->contains('running') === false) {
            return 'service_not_running';
        }

        return null;
    }

    private function processDockerCleanups(): void
    {
        $this->getServersForCleanupQuery()
            ->chunkById(self::CHUNK_SIZE, function ($servers): void {
                foreach ($servers as $server) {
                    $this->processDockerCleanup($server);
                }
            });
    }

    private function processDockerCleanup(Server $server): void
    {
        try {
            $skipReason = $this->getDockerCleanupSkipReason($server);
            if ($skipReason !== null) {
                $this->skippedCount++;
                $this->logSkip('docker_cleanup', $skipReason, [
                    'server_id' => $server->id,
                    'server_name' => $server->name,
                    'team_id' => $server->team_id,
                ]);

                return;
            }

            $frequency = data_get($server->settings, 'docker_cleanup_frequency', '0 * * * *');

            if ($this->shouldDispatch($frequency, $server, "docker-cleanup:{$server->id}")) {
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

    private function getServersForCleanupQuery(): Builder
    {
        $query = Server::with('settings')
            ->where('ip', '!=', '1.2.3.4');

        if (isCloud()) {
            $query
                ->with('team.subscription')
                ->where(function (Builder $query): void {
                    $query
                        ->where('team_id', 0)
                        ->orWhereRelation('team.subscription', 'stripe_invoice_paid', true);
                });
        }

        return $query;
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

    private function shouldDispatch(string $frequency, Server $server, string $dedupKey): bool
    {
        return shouldRunCronNow(
            $this->normalizeFrequency($frequency),
            $this->serverTimezone($server),
            $dedupKey,
            $this->executionTime,
        );
    }

    private function isDueCandidateBeforeExpensiveChecks(string $frequency, Server $server, string $dedupKey): bool
    {
        $cron = new CronExpression($this->normalizeFrequency($frequency));
        $executionTime = ($this->executionTime ?? Carbon::now())->copy()->setTimezone($this->serverTimezone($server));
        $lastDispatched = Cache::get($dedupKey);
        $previousDue = Carbon::instance($cron->getPreviousRunDate($executionTime, allowCurrentDate: true));

        if ($lastDispatched === null) {
            $isDue = $cron->isDue($executionTime);

            if (! $isDue) {
                Cache::put($dedupKey, $previousDue->toIso8601String(), 2592000);
            }

            return $isDue;
        }

        $shouldFire = $previousDue->gt(Carbon::parse($lastDispatched));

        if (! $shouldFire) {
            Cache::put($dedupKey, $previousDue->toIso8601String(), 2592000);
        }

        return $shouldFire;
    }

    private function normalizeFrequency(string $frequency): string
    {
        return VALID_CRON_STRINGS[$frequency] ?? $frequency;
    }

    private function serverTimezone(Server $server): string
    {
        $timezone = data_get($server->settings, 'server_timezone', config('app.timezone'));

        return validate_timezone($timezone) ? $timezone : config('app.timezone');
    }

    private function logBackupSkip(ScheduledDatabaseBackup $backup, string $reason): void
    {
        $this->logSkip('backup', $reason, [
            'backup_id' => $backup->id,
            'database_id' => $backup->database_id,
            'database_type' => $backup->database_type,
            'team_id' => $backup->team_id ?? null,
        ]);
    }

    private function logTaskSkip(ScheduledTask $task, string $reason, ?Server $server): void
    {
        $this->logSkip('task', $reason, [
            'task_id' => $task->id,
            'task_name' => $task->name,
            'team_id' => $server?->team_id,
        ]);
    }
}
