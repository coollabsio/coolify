<?php

namespace App\Jobs;

use App\Actions\Shared\DeleteScheduledVolumeBackup;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledJobDelivery;
use App\Models\ScheduledTask;
use App\Models\ScheduledVolumeBackup;
use App\Models\ScheduledVolumeBackupExecution;
use App\Models\Server;
use App\Models\ServiceDatabase;
use App\Services\ScheduledJobDeliveryService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Dispatches the due scheduled backups, tasks, volume backups, and Docker cleanups.
 *
 * Each schedule stores its next due time in next_run_at. A run selects only due rows and claims
 * each occurrence with an atomic update of next_run_at, so parallel runs on several nodes never
 * dispatch the same occurrence twice. The `scheduled:dispatch` command runs it every minute.
 */
class ScheduledJobManager
{
    private const CHUNK_SIZE = 100;

    /**
     * A task or Docker cleanup that is due longer than this is logged as missed and not run.
     * Database and volume backups always run once, also when they are late.
     */
    public const LATE_RUN_WINDOW_MINUTES = 10;

    private CarbonImmutable $now;

    private ScheduledJobDeliveryService $deliveries;

    /** @var array{dispatched: int, skipped: int, missed: int} */
    private array $counts = ['dispatched' => 0, 'skipped' => 0, 'missed' => 0];

    public function handle(): void
    {
        $this->now = CarbonImmutable::now();
        $this->deliveries = app(ScheduledJobDeliveryService::class);
        $this->counts = ['dispatched' => 0, 'skipped' => 0, 'missed' => 0];

        // Read the current server state, not servers cached by an earlier run in this process.
        Server::flushIdentityMap();

        Log::channel('scheduled')->info('ScheduledJobManager started', ['execution_time' => $this->now->toIso8601String()]);

        $this->runStep('stale enqueued occurrences', fn () => $this->deliveries->recoverStaleEnqueued());
        $this->deliveries->publishPending();
        $this->runStep('database backups', fn () => $this->processDatabaseBackups());
        $this->runStep('scheduled tasks', fn () => $this->processScheduledTasks());
        $this->runStep('volume backups', function () {
            $this->recoverStoppedVolumeBackupContainers();
            $this->processVolumeBackups();
        });
        $this->runStep('docker cleanups', fn () => $this->processDockerCleanups());

        Log::channel('scheduled')->info('ScheduledJobManager completed', [
            'execution_time' => $this->now->toIso8601String(),
            'duration_ms' => $this->now->diffInMilliseconds(CarbonImmutable::now()),
            ...$this->counts,
        ]);

        // The UI uses the heartbeat to show when the scheduler has stopped.
        rescue(fn () => Cache::put('scheduled-job-manager:heartbeat', now()->toIso8601String(), 300), report: false);
    }

    private function runStep(string $name, callable $step): void
    {
        try {
            $step();
        } catch (\Throwable $e) {
            Log::channel('scheduled-errors')->error("Failed to process {$name}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    private function processDatabaseBackups(): void
    {
        $this->due(ScheduledDatabaseBackup::query())
            ->with([
                'team.subscription',
                'database' => fn (MorphTo $morphTo) => $morphTo->morphWith([
                    ServiceDatabase::class => ['service.destination.server.settings', 'service.destination.server.team.subscription'],
                    ...array_fill_keys(STANDALONE_DATABASE_MODELS, ['destination.server.settings', 'destination.server.team.subscription']),
                ]),
            ])
            ->chunkById(self::CHUNK_SIZE, fn ($backups) => $backups->each(fn (ScheduledDatabaseBackup $backup) => $this->processItem(
                'backup',
                ['backup_id' => $backup->id, 'database_id' => $backup->database_id, 'database_type' => $backup->database_type, 'team_id' => $backup->team_id],
                fn () => $this->processDatabaseBackup($backup),
            )));
    }

    private function processDatabaseBackup(ScheduledDatabaseBackup $backup): array
    {
        $server = $backup->server();

        if (blank($backup->database) || blank($server)) {
            $backup->delete();

            return ['deleted' => blank($backup->database) ? 'database_deleted' : 'server_deleted'];
        }

        return $this->runOccurrence(
            schedule: $backup,
            frequency: $backup->frequency,
            server: $server,
            runLate: true,
            skipReason: fn () => $this->serverSkipReason($server),
            delivery: ['schedule_key' => "scheduled-backup:{$backup->id}", 'job_type' => 'database-backup', 'resource_id' => $backup->id],
        );
    }

    private function processScheduledTasks(): void
    {
        $this->due(ScheduledTask::query())
            ->with([
                'service.destination.server.settings',
                'service.destination.server.team.subscription',
                'application.destination.server.settings',
                'application.destination.server.team.subscription',
            ])
            ->chunkById(self::CHUNK_SIZE, fn ($tasks) => $tasks->each(fn (ScheduledTask $task) => $this->processItem(
                'task',
                ['task_id' => $task->id, 'task_name' => $task->name, 'team_id' => $task->team_id],
                fn () => $this->processScheduledTask($task),
            )));
    }

    private function processScheduledTask(ScheduledTask $task): array
    {
        $server = $task->server();

        if (blank($server) || (! $task->service && ! $task->application)) {
            $task->delete();

            return ['deleted' => blank($server) ? 'server_deleted' : 'resource_deleted'];
        }

        return $this->runOccurrence(
            schedule: $task,
            frequency: $task->frequency,
            server: $server,
            runLate: false,
            skipReason: fn () => $this->serverSkipReason($server) ?? $this->taskResourceSkipReason($task),
            delivery: ['schedule_key' => "scheduled-task:{$task->id}", 'job_type' => 'scheduled-task', 'resource_id' => $task->id],
        );
    }

    private function processVolumeBackups(): void
    {
        $this->due(ScheduledVolumeBackup::query())
            ->with(['backupable', 'team.subscription'])
            ->chunkById(self::CHUNK_SIZE, fn ($backups) => $backups->each(fn (ScheduledVolumeBackup $backup) => $this->processItem(
                'volume_backup',
                ['backup_id' => $backup->id, 'backupable_type' => $backup->backupable_type, 'backupable_id' => $backup->backupable_id, 'team_id' => $backup->team_id],
                fn () => $this->processVolumeBackup($backup),
            )));
    }

    private function processVolumeBackup(ScheduledVolumeBackup $backup): array
    {
        $server = $backup->server();

        // Wait without claiming: the backup runs late when the recovery is done.
        if ($backup->executions()->where(fn (Builder $query) => $query->where('stop_recovery_pending', true)->orWhere('s3_cleanup_pending', true))->exists()) {
            return ['waiting' => 'container_recovery_pending'];
        }

        if (! $backup->backupable) {
            DeleteScheduledVolumeBackup::run($backup, $server);

            return ['deleted' => 'resource_deleted'];
        }

        if (! $server) {
            return ['waiting' => 'server_missing'];
        }

        return $this->runOccurrence(
            schedule: $backup,
            frequency: $backup->frequency,
            server: $server,
            runLate: true,
            skipReason: fn () => $this->serverSkipReason($server),
            delivery: ['schedule_key' => "scheduled-volume-backup:{$backup->id}", 'job_type' => 'volume-backup', 'resource_id' => $backup->id],
        );
    }

    private function recoverStoppedVolumeBackupContainers(): void
    {
        ScheduledVolumeBackupExecution::query()
            ->where(fn (Builder $query) => $query
                ->where('stop_recovery_pending', true)
                ->orWhere('s3_cleanup_pending', true))
            ->chunkById(self::CHUNK_SIZE, function ($executions): void {
                foreach ($executions as $execution) {
                    VolumeBackupRecoveryJob::dispatch($execution);
                }
            });
    }

    private function processDockerCleanups(): void
    {
        $query = Server::query()
            ->with('settings')
            ->whereNotNull('ip')
            ->where('ip', '!=', '')
            ->whereNotIn('ip', Server::PLACEHOLDER_IPS)
            ->whereHas('settings', fn (Builder $query) => $query
                ->whereNull('docker_cleanup_next_run_at')
                ->orWhere('docker_cleanup_next_run_at', '<=', $this->now));

        if (isCloud()) {
            $query->with('team.subscription')->where(fn (Builder $query) => $query
                ->where('team_id', 0)
                ->orWhereRelation('team.subscription', 'stripe_invoice_paid', true));
        }

        $query->chunkById(self::CHUNK_SIZE, fn ($servers) => $servers->each(fn (Server $server) => $this->processItem(
            'docker_cleanup',
            ['server_id' => $server->id, 'server_name' => $server->name, 'team_id' => $server->team_id],
            fn () => $this->runOccurrence(
                schedule: $server->settings,
                frequency: $server->settings->docker_cleanup_frequency ?? '0 0 * * *',
                server: $server,
                runLate: false,
                skipReason: fn () => $this->serverSkipReason($server),
                delivery: [
                    'schedule_key' => "docker-cleanup:{$server->id}",
                    'job_type' => 'docker-cleanup',
                    'resource_id' => $server->id,
                    'payload' => [
                        'delete_unused_volumes' => $server->settings->delete_unused_volumes,
                        'delete_unused_networks' => $server->settings->delete_unused_networks,
                    ],
                ],
                column: 'docker_cleanup_next_run_at',
            ),
        )));
    }

    /**
     * Enabled schedules that are due, or that have no next run yet.
     */
    private function due(Builder $query): Builder
    {
        return $query
            ->where('enabled', true)
            ->where(fn (Builder $query) => $query->whereNull('next_run_at')->orWhere('next_run_at', '<=', $this->now));
    }

    /**
     * Claim the due occurrence of a schedule and dispatch it, skip it, or log it as missed.
     *
     * @param  array{schedule_key: string, job_type: string, resource_id: int, payload?: array<string, mixed>}  $delivery
     * @return array<string, mixed> The outcome, for the log.
     */
    private function runOccurrence(
        Model $schedule,
        string $frequency,
        Server $server,
        bool $runLate,
        callable $skipReason,
        array $delivery,
        string $column = 'next_run_at',
    ): array {
        $timezone = data_get($server, 'settings.server_timezone');
        $stored = $schedule->getRawOriginal($column);

        if ($stored === null) {
            // First run after the upgrade, a timezone change, or a move to another server.
            $first = next_cron_run_at($frequency, $timezone, $this->now, includeCurrentMinute: true);
            if ($first === null) {
                return $this->invalidFrequency($schedule, $column, null, $frequency, $delivery['schedule_key']);
            }
            if (! $this->advance($schedule, $column, null, $first)) {
                return [];
            }
            if ($first->gt($this->now)) {
                return [];
            }
            $stored = $first;
        }

        $dueAt = CarbonImmutable::instance($schedule->getAttribute($column))->utc();
        $next = next_cron_run_at($frequency, $timezone, $this->now);
        if ($next === null) {
            return $this->invalidFrequency($schedule, $column, $stored, $frequency, $delivery['schedule_key']);
        }
        // When the clocks go back, a local time occurs two times. Run it only once.
        if ($this->sameLocalTime($next, $dueAt, $timezone)) {
            $next = next_cron_run_at($frequency, $timezone, $next) ?? $next;
        }

        $minutesLate = (int) $dueAt->diffInMinutes($this->now);
        if (! $runLate && $minutesLate > self::LATE_RUN_WINDOW_MINUTES) {
            $outcome = ['missed' => $dueAt->toIso8601String(), 'minutes_late' => $minutesLate];
        } elseif (($reason = $skipReason()) !== null) {
            $outcome = ['skipped' => $reason];
        } else {
            $outcome = ['dispatched' => $dueAt->toIso8601String()];
        }

        // Claim and record the occurrence together, so a crash cannot claim it without a delivery.
        $occurrence = DB::transaction(function () use ($schedule, $column, $stored, $next, $outcome, $delivery, $dueAt): ScheduledJobDelivery|bool {
            if (! $this->advance($schedule, $column, $stored, $next)) {
                return false;
            }

            if (! isset($outcome['dispatched'])) {
                return true;
            }

            return $this->deliveries->create($delivery['schedule_key'], $dueAt, $delivery['job_type'], $delivery['resource_id'], $delivery['payload'] ?? []) ?? true;
        });

        if ($occurrence === false) {
            return [];
        }

        if ($occurrence instanceof ScheduledJobDelivery) {
            $this->deliveries->publish($occurrence);
        }

        return [...$outcome, 'server_id' => $server->id, 'next_run_at' => $next->toIso8601String()];
    }

    /**
     * Park a schedule whose frequency does not parse: set next_run_at to null, which the UI, the API,
     * and `scheduled:diagnostics` show as an invalid frequency, and log it once a day instead of
     * every minute. Saving a valid frequency calculates next_run_at again (HasNextRunAt, ServerSetting).
     *
     * @return array<string, mixed>
     */
    private function invalidFrequency(Model $schedule, string $column, mixed $stored, string $frequency, string $scheduleKey): array
    {
        if ($stored !== null) {
            $this->advance($schedule, $column, $stored, null);
        }

        $firstReportToday = rescue(
            fn () => Cache::add("scheduled-job-manager:invalid-frequency:{$scheduleKey}:".md5($frequency), true, now()->addDay()),
            true,
            report: false,
        );

        return $firstReportToday ? ['invalid_frequency' => $frequency] : [];
    }

    /**
     * Move next_run_at from the stored value to $to. Only one process can win this update.
     */
    private function advance(Model $schedule, string $column, mixed $from, ?CarbonImmutable $to): bool
    {
        $updated = $schedule->newQuery()
            ->whereKey($schedule->getKey())
            ->when($from === null, fn (Builder $query) => $query->whereNull($column), fn (Builder $query) => $query->where($column, $from))
            ->toBase()
            ->update([$column => $to]);

        if ($updated === 1) {
            $schedule->setAttribute($column, $to)->syncOriginalAttribute($column);
        }

        return $updated === 1;
    }

    private function sameLocalTime(CarbonImmutable $first, CarbonImmutable $second, ?string $timezone): bool
    {
        $timezone = filled($timezone) && validate_timezone($timezone) ? $timezone : config('app.timezone');

        return $first->setTimezone($timezone)->format('Y-m-d H:i') === $second->setTimezone($timezone)->format('Y-m-d H:i');
    }

    private function serverSkipReason(Server $server): ?string
    {
        if (! $server->isFunctional()) {
            return 'server_not_functional';
        }

        if (isCloud() && $server->team_id !== 0 && data_get($server->team?->subscription, 'stripe_invoice_paid', false) === false) {
            return 'subscription_unpaid';
        }

        return null;
    }

    private function taskResourceSkipReason(ScheduledTask $task): ?string
    {
        if ($task->application && ! str($task->application->status)->contains('running')) {
            return 'application_not_running';
        }

        if ($task->service && ! str($task->service->status)->contains('running')) {
            return 'service_not_running';
        }

        return null;
    }

    /**
     * Process one schedule and log its outcome. An error in one schedule never stops the others.
     *
     * @param  array<string, mixed>  $context
     */
    private function processItem(string $type, array $context, callable $process): void
    {
        try {
            $outcome = $process();
        } catch (\Throwable $e) {
            Log::channel('scheduled-errors')->error("Error processing {$type}", [...$context, 'error' => $e->getMessage()]);

            return;
        }

        $label = ucfirst(str_replace('_', ' ', $type));
        $context = [...$context, ...$outcome, 'execution_time' => $this->now->toIso8601String()];

        match (true) {
            isset($outcome['dispatched']) => $this->log('dispatched', 'info', "{$label} dispatched", $context),
            isset($outcome['skipped']) => $this->log('skipped', 'info', "{$label} skipped", ['skip_reason' => $outcome['skipped'], ...$context]),
            isset($outcome['deleted']) => $this->log('skipped', 'info', "{$label} skipped", ['skip_reason' => $outcome['deleted'], ...$context]),
            isset($outcome['waiting']) => $this->log('skipped', 'info', "{$label} skipped", ['skip_reason' => $outcome['waiting'], ...$context]),
            isset($outcome['missed']) => $this->log('missed', 'warning', "{$label} missed", $context),
            isset($outcome['invalid_frequency']) => Log::channel('scheduled-errors')->warning("{$label} has an invalid frequency", $context),
            default => null,
        };
    }

    /**
     * @param  'dispatched'|'skipped'|'missed'  $counter
     * @param  array<string, mixed>  $context
     */
    private function log(string $counter, string $level, string $message, array $context): void
    {
        $this->counts[$counter]++;
        Log::channel('scheduled')->log($level, $message, $context);
    }
}
