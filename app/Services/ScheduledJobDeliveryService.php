<?php

namespace App\Services;

use App\Events\BackupCreated;
use App\Events\DockerCleanupDone;
use App\Events\ScheduledTaskDone;
use App\Jobs\DatabaseBackupJob;
use App\Jobs\DockerCleanupJob;
use App\Jobs\ScheduledTaskJob;
use App\Jobs\VolumeBackupJob;
use App\Models\DockerCleanupExecution;
use App\Models\NotificationThrottle;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\ScheduledJobDelivery;
use App\Models\ScheduledJobState;
use App\Models\ScheduledTask;
use App\Models\ScheduledTaskExecution;
use App\Models\ScheduledVolumeBackup;
use App\Models\ScheduledVolumeBackupExecution;
use App\Models\Server;
use App\Models\Team;
use App\Notifications\Database\BackupFailed;
use App\Notifications\ScheduledTask\TaskFailed;
use App\Notifications\Server\DockerCleanupFailed;
use App\Notifications\VolumeBackup\BackupFailed as VolumeBackupFailed;
use Closure;
use Cron\CronExpression;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ScheduledJobDeliveryService
{
    public const CATCH_UP_WINDOW_MINUTES = 10;

    /**
     * An occurrence that is enqueued longer than this has most likely lost its queued job, for
     * example when Redis evicted or lost the queue. A job that is only waiting in a long queue is
     * safe as well: the first job that claims the occurrence runs it, and the other job exits.
     */
    public const ENQUEUED_STALE_AFTER_MINUTES = 60;

    /**
     * At most this many open occurrences are published again or recovered per scheduler run, so a
     * large backlog after an outage is worked off in steps instead of in one long scheduler tick.
     */
    public const RECOVERY_BATCH_SIZE = 500;

    /**
     * A claimed occurrence that started longer ago than the worker timeout plus this margin has no
     * live worker: Horizon kills every job at the worker timeout.
     */
    public const INTERRUPTED_CLAIM_MARGIN_MINUTES = 15;

    public const MISSED_OCCURRENCE_NOTIFICATION = 'scheduled-occurrence-missed';

    public const MISSED_OCCURRENCE_NOTIFICATION_INTERVAL_MINUTES = 60;

    /**
     * Job types that run once when they are late. A lost task or Docker cleanup is not run; it is
     * recorded as a failed execution and the team is notified.
     */
    private const LATE_RUN_JOB_TYPES = ['database-backup', 'volume-backup'];

    /**
     * A lost backup occurrence runs late only while it is younger than this. Older work is not
     * replayed, for example after a long Redis or worker outage.
     */
    public const LATE_RUN_MAX_AGE_HOURS = 24;

    public function recordAndPublish(
        string $scheduleKey,
        string $frequency,
        string $timezone,
        string $jobType,
        int $resourceId,
        array $payload = [],
        ?Carbon $executionTime = null,
    ): bool {
        $executionTime = ($executionTime ?? Carbon::now())->copy()->setTimezone($timezone);
        $cron = new CronExpression(VALID_CRON_STRINGS[$frequency] ?? $frequency);
        $scheduledFor = Carbon::instance($cron->getPreviousRunDate($executionTime, allowCurrentDate: true));

        if (! $scheduledFor->gte($executionTime->copy()->subMinutes(self::CATCH_UP_WINDOW_MINUTES))) {
            return false;
        }

        $delivery = DB::transaction(function () use ($scheduleKey, $scheduledFor, $cron, $timezone, $jobType, $resourceId, $payload): ?ScheduledJobDelivery {
            ScheduledJobState::query()->insertOrIgnore([
                'uuid' => new_public_id(),
                'schedule_key' => $scheduleKey,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $state = ScheduledJobState::query()
                ->where('schedule_key', $scheduleKey)
                ->lockForUpdate()
                ->firstOrFail();

            if ($this->isDuplicateOccurrence($state, $scheduledFor, $cron, $timezone)) {
                return null;
            }

            $state->update(['last_scheduled_for' => $scheduledFor->utc()]);

            return ScheduledJobDelivery::create([
                'schedule_key' => $scheduleKey,
                'scheduled_for' => $scheduledFor->utc(),
                'job_type' => $jobType,
                'resource_id' => $resourceId,
                'payload' => $payload,
                'status' => 'pending',
            ]);
        });

        if ($delivery === null) {
            return false;
        }

        return $this->publish($delivery);
    }

    public function recordSkipped(
        string $scheduleKey,
        string $frequency,
        string $timezone,
        ?Carbon $executionTime = null,
    ): bool {
        $executionTime = ($executionTime ?? Carbon::now())->copy()->setTimezone($timezone);
        $cron = new CronExpression(VALID_CRON_STRINGS[$frequency] ?? $frequency);
        $scheduledFor = Carbon::instance($cron->getPreviousRunDate($executionTime, allowCurrentDate: true));

        if (! $scheduledFor->gte($executionTime->copy()->subMinutes(self::CATCH_UP_WINDOW_MINUTES))) {
            return false;
        }

        return DB::transaction(function () use ($scheduleKey, $scheduledFor, $cron, $timezone): bool {
            ScheduledJobState::query()->insertOrIgnore([
                'uuid' => new_public_id(),
                'schedule_key' => $scheduleKey,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $state = ScheduledJobState::query()
                ->where('schedule_key', $scheduleKey)
                ->lockForUpdate()
                ->firstOrFail();

            if ($this->isDuplicateOccurrence($state, $scheduledFor, $cron, $timezone)) {
                return false;
            }

            $state->update(['last_scheduled_for' => $scheduledFor->utc()]);

            return true;
        });
    }

    /**
     * The same schedule can have the same local time twice when daylight saving time ends. A
     * schedule with fixed hours (for example `30 2 * * *`) then runs once, like cron does. Interval
     * schedules (`*` or `/` in the hour field) still run in both hours.
     */
    private function isDuplicateOccurrence(ScheduledJobState $state, Carbon $scheduledFor, CronExpression $cron, string $timezone): bool
    {
        $last = $state->last_scheduled_for;
        if ($last === null) {
            return false;
        }

        if ($last->gte($scheduledFor)) {
            return true;
        }

        if (preg_match('/^[\d,\-]+$/', (string) $cron->getExpression(CronExpression::HOUR)) !== 1) {
            return false;
        }

        $format = 'Y-m-d H:i';

        return $last->copy()->setTimezone($timezone)->format($format) === $scheduledFor->copy()->setTimezone($timezone)->format($format);
    }

    /**
     * Publish occurrences again whose publication did not finish, for example because Redis
     * rejected the job. An occurrence that stays pending longer than the stale limit follows the
     * same late-run policy as a lost queued job (see recoverStaleEnqueued()).
     *
     * @param  array<int, string>|null  $jobTypes  Only these job types, or all when null.
     */
    public function publishPending(?array $jobTypes = null): void
    {
        $occurrences = ScheduledJobDelivery::query()
            ->where('status', 'pending')
            ->when($jobTypes !== null, fn ($query) => $query->whereIn('job_type', $jobTypes))
            ->orderBy('id')
            ->limit(self::RECOVERY_BATCH_SIZE)
            ->get();

        foreach ($occurrences as $occurrence) {
            try {
                if ($occurrence->created_at->lt(now()->subMinutes(self::ENQUEUED_STALE_AFTER_MINUTES))) {
                    $this->recoverUnstartedOccurrence($occurrence);

                    continue;
                }

                $this->logOccurrence($occurrence, 'Scheduled occurrence publishing again', 'warning');
                $this->publish($occurrence);
            } catch (\Throwable $e) {
                Log::channel('scheduled-errors')->error('Failed to publish pending scheduled occurrence', [
                    'occurrence_uuid' => $occurrence->uuid,
                    'schedule_key' => $occurrence->schedule_key,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Count occurrences that are still open 15 minutes after they were created. Pending or
     * enqueued rows here never started; claimed rows are still running or their worker died.
     *
     * @return array<string, int>
     */
    public function staleOpenOccurrenceCounts(): array
    {
        return ScheduledJobDelivery::query()
            ->whereIn('status', ['pending', 'enqueued', 'claimed'])
            ->where('created_at', '<', now()->subMinutes(15))
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /**
     * Publish backups again whose queued job was not started, and report lost tasks and Docker
     * cleanups as missed. A lost backup is not run when a newer occurrence of the same schedule
     * exists, so a backup that runs longer than its interval does not build up a backlog.
     *
     * @param  array<int, string>|null  $jobTypes  Only these job types, or all when null.
     */
    public function recoverStaleEnqueued(?array $jobTypes = null): void
    {
        $occurrences = ScheduledJobDelivery::query()
            ->where('status', 'enqueued')
            ->when($jobTypes !== null, fn ($query) => $query->whereIn('job_type', $jobTypes))
            ->where('enqueued_at', '<', now()->subMinutes(self::ENQUEUED_STALE_AFTER_MINUTES))
            ->orderBy('id')
            ->limit(self::RECOVERY_BATCH_SIZE)
            ->get();

        foreach ($occurrences as $occurrence) {
            try {
                $this->recoverUnstartedOccurrence($occurrence);
            } catch (\Throwable $e) {
                Log::channel('scheduled-errors')->error('Failed to recover enqueued scheduled occurrence', [
                    'occurrence_uuid' => $occurrence->uuid,
                    'schedule_key' => $occurrence->schedule_key,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Mark claimed occurrences as failed when no worker can still run them. Horizon kills a job at
     * the worker timeout, so a claim older than that lost its worker (for example a killed
     * container). The job is not run again: it can have done part of its remote work already.
     *
     * @param  array<int, string>|null  $jobTypes  Only these job types, or all when null.
     */
    public function failInterruptedClaims(?array $jobTypes = null): void
    {
        $startedBefore = now()
            ->subSeconds((int) config('horizon.worker_timeout'))
            ->subMinutes(self::INTERRUPTED_CLAIM_MARGIN_MINUTES);

        $occurrences = ScheduledJobDelivery::query()
            ->where('status', 'claimed')
            ->when($jobTypes !== null, fn ($query) => $query->whereIn('job_type', $jobTypes))
            ->where('started_at', '<', $startedBefore)
            ->orderBy('id')
            ->limit(self::RECOVERY_BATCH_SIZE)
            ->get(['id', 'uuid', 'claim_token']);

        foreach ($occurrences as $occurrence) {
            $failed = ScheduledJobDelivery::query()
                ->whereKey($occurrence->id)
                ->where('status', 'claimed')
                ->where('claim_token', $occurrence->claim_token)
                ->update(['status' => 'failed', 'updated_at' => now()]) === 1;

            if ($failed) {
                $this->logOccurrence($occurrence->uuid, 'Scheduled occurrence interrupted: its worker stopped after it claimed the occurrence', 'warning');
            }
        }
    }

    /**
     * Decide what happens to an occurrence that never started: a pending row whose publication kept
     * failing, or an enqueued row whose queued job was lost. Backups run once late unless a newer
     * occurrence exists; tasks and Docker cleanups are reported as missed.
     */
    private function recoverUnstartedOccurrence(ScheduledJobDelivery $occurrence): void
    {
        $staleBefore = now()->subMinutes(self::ENQUEUED_STALE_AFTER_MINUTES);

        // complete() deletes the row of a finished occurrence, so the schedule state also counts.
        $hasNewerOccurrence = ScheduledJobState::query()
            ->where('schedule_key', $occurrence->schedule_key)
            ->where('last_scheduled_for', '>', $occurrence->scheduled_for)
            ->exists()
            || ScheduledJobDelivery::query()
                ->where('schedule_key', $occurrence->schedule_key)
                ->where('scheduled_for', '>', $occurrence->scheduled_for)
                ->exists();
        $tooOld = $occurrence->scheduled_for->lt(now()->subHours(self::LATE_RUN_MAX_AGE_HOURS));
        $runLate = in_array($occurrence->job_type, self::LATE_RUN_JOB_TYPES, true) && ! $hasNewerOccurrence && ! $tooOld;

        // Only one scheduler node can move each occurrence.
        $moved = ScheduledJobDelivery::query()
            ->whereKey($occurrence->id)
            ->where('status', $occurrence->status)
            ->when(
                $occurrence->status === 'enqueued',
                fn ($query) => $query->where('enqueued_at', '<', $staleBefore),
                fn ($query) => $query->where('created_at', '<', $staleBefore),
            )
            ->update(['status' => $runLate ? 'pending' : 'failed', 'updated_at' => now()]) === 1;

        if (! $moved) {
            return;
        }

        if (! $runLate) {
            $this->logOccurrence($occurrence->uuid, match (true) {
                $hasNewerOccurrence => 'Scheduled occurrence skipped: its queued job was not started and a newer occurrence exists',
                $tooOld && in_array($occurrence->job_type, self::LATE_RUN_JOB_TYPES, true) => 'Scheduled occurrence missed: its queued job was not started and it is too old to run late',
                default => 'Scheduled occurrence missed: its queued job was not started',
            }, 'warning', ['previous_status' => $occurrence->status]);
            $this->reportMissedOccurrence($occurrence);

            return;
        }

        $occurrence->status = 'pending';
        $this->logOccurrence($occurrence->uuid, 'Scheduled occurrence publishing again: its queued job was not started', 'warning');
        $this->publish($occurrence);
    }

    /**
     * Show a missed task, Docker cleanup, or backup as a failed execution and send the failure notification.
     * The caller moved the occurrence out of `enqueued` atomically, so this runs once per occurrence,
     * and claim() rejects the late job if it starts afterwards.
     */
    private function reportMissedOccurrence(ScheduledJobDelivery $occurrence): void
    {
        $message = 'Skipped: the queued job did not start within '.self::ENQUEUED_STALE_AFTER_MINUTES.' minutes.';

        try {
            if ($occurrence->job_type === 'scheduled-task') {
                $task = ScheduledTask::find($occurrence->resource_id);
                if (! $task) {
                    return;
                }

                ScheduledTaskExecution::create([
                    'scheduled_task_id' => $task->id,
                    'status' => 'failed',
                    'message' => $message,
                    'retry_count' => 0,
                    'finished_at' => now(),
                ]);
                ScheduledTaskDone::dispatch($task->team_id);
                $this->notifyMissedOccurrence($task, fn () => Team::find($task->team_id)?->notify(new TaskFailed($task, $message)));
            } elseif ($occurrence->job_type === 'docker-cleanup') {
                $server = Server::find($occurrence->resource_id);
                if (! $server) {
                    return;
                }

                $execution = DockerCleanupExecution::create([
                    'server_id' => $server->id,
                    'status' => 'failed',
                    'message' => $message,
                    'finished_at' => now(),
                ]);
                event(new DockerCleanupDone($execution));
                $this->notifyMissedOccurrence($server, fn () => $server->team?->notify(new DockerCleanupFailed($server, "Docker cleanup job failed with the following error: {$message}")));
            } elseif ($occurrence->job_type === 'database-backup') {
                $backup = ScheduledDatabaseBackup::with(['team', 'database'])->find($occurrence->resource_id);
                if (! $backup?->database) {
                    return;
                }

                $databaseName = $backup->dump_all ? 'all' : $backup->databases_to_backup;
                ScheduledDatabaseBackupExecution::create([
                    'uuid' => new_public_id(),
                    'database_name' => filled($databaseName) ? str($databaseName)->limit(250)->toString() : null,
                    'scheduled_database_backup_id' => $backup->id,
                    'status' => 'failed',
                    'message' => $message,
                    'local_storage_deleted' => false,
                    'finished_at' => now(),
                ]);
                BackupCreated::dispatch($backup->team_id);
                $this->notifyMissedOccurrence($backup, fn () => $backup->team?->notify(new BackupFailed($backup, $backup->database, $message, $databaseName ?? 'unknown')));
            } elseif ($occurrence->job_type === 'volume-backup') {
                $backup = ScheduledVolumeBackup::with(['team', 'backupable.resource'])->find($occurrence->resource_id);
                if (! $backup) {
                    return;
                }

                ScheduledVolumeBackupExecution::create([
                    'scheduled_volume_backup_id' => $backup->id,
                    'status' => 'failed',
                    'message' => $message,
                    'finished_at' => now(),
                ]);
                BackupCreated::dispatch($backup->team_id);
                $this->notifyMissedOccurrence($backup, fn () => $backup->team?->notify(new VolumeBackupFailed($backup, $message)));
            }
        } catch (\Throwable $e) {
            Log::channel('scheduled-errors')->error('Failed to report missed scheduled occurrence', [
                'occurrence_uuid' => $occurrence->uuid,
                'schedule_key' => $occurrence->schedule_key,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Every missed run gets its own failed execution, but the team gets at most one missed-run
     * notification per schedule each hour, so dead queue workers do not cause a notification storm.
     */
    private function notifyMissedOccurrence(Model $schedule, Closure $send): void
    {
        NotificationThrottle::sendOnce($schedule, self::MISSED_OCCURRENCE_NOTIFICATION, now()->subMinutes(self::MISSED_OCCURRENCE_NOTIFICATION_INTERVAL_MINUTES), $send);
    }

    public function deleteOldOccurrences(): void
    {
        ScheduledJobDelivery::query()
            ->where('status', 'claimed')
            ->where('updated_at', '<', now()->subDays(2))
            ->update([
                'status' => 'failed',
                'updated_at' => now(),
            ]);

        ScheduledJobDelivery::query()
            ->whereIn('status', ['failed', 'skipped'])
            ->where('created_at', '<', now()->subDays(30))
            ->chunkById(100, function ($occurrences): void {
                ScheduledJobDelivery::query()->whereKey($occurrences->modelKeys())->delete();
            });
    }

    public function claim(string $uuid, string $claimToken): bool
    {
        $claimed = ScheduledJobDelivery::query()
            ->where('uuid', $uuid)
            ->whereIn('status', ['pending', 'enqueued'])
            ->update([
                'status' => 'claimed',
                'claim_token' => $claimToken,
                'started_at' => now(),
                'updated_at' => now(),
            ]) === 1
            || ScheduledJobDelivery::query()
                ->where('uuid', $uuid)
                ->where('status', 'claimed')
                ->where('claim_token', $claimToken)
                ->exists();

        $this->logOccurrence($uuid, $claimed ? 'Scheduled occurrence claimed' : 'Scheduled occurrence claim rejected', $claimed ? 'info' : 'warning');

        return $claimed;
    }

    public function complete(string $uuid, string $claimToken): void
    {
        $this->logOccurrence($uuid, 'Scheduled occurrence completed', context: ['status' => 'completed']);

        ScheduledJobDelivery::query()
            ->where('uuid', $uuid)
            ->where('claim_token', $claimToken)
            ->delete();
    }

    /**
     * Skip an occurrence that no job has claimed, so stale recovery does not publish it again.
     */
    public function skip(string $uuid, string $reason): void
    {
        $skipped = ScheduledJobDelivery::query()
            ->where('uuid', $uuid)
            ->whereIn('status', ['pending', 'enqueued'])
            ->update([
                'status' => 'skipped',
                'updated_at' => now(),
            ]) === 1;

        if ($skipped) {
            $this->logOccurrence($uuid, "Scheduled occurrence skipped: {$reason}", 'warning');
        }
    }

    /**
     * WithoutOverlapping for the job of a scheduled occurrence. When another run holds the lock,
     * the job does not run and its occurrence is skipped, so stale recovery does not run it later.
     */
    public static function withoutOverlapping(string $key, ?string $occurrenceUuid): WithoutOverlapping
    {
        return new class($key, $occurrenceUuid) extends WithoutOverlapping
        {
            public function __construct(string $key, private ?string $occurrenceUuid)
            {
                parent::__construct($key);
            }

            public function handle($job, $next)
            {
                $started = false;
                parent::handle($job, function ($job) use ($next, &$started) {
                    $started = true;

                    return $next($job);
                });

                if (! $started && $this->occurrenceUuid !== null) {
                    app(ScheduledJobDeliveryService::class)->skip($this->occurrenceUuid, 'the previous run of this schedule is still running');
                }
            }
        };
    }

    public function fail(string $uuid, string $claimToken): void
    {
        ScheduledJobDelivery::query()
            ->where('uuid', $uuid)
            ->where('claim_token', $claimToken)
            ->update([
                'status' => 'failed',
                'updated_at' => now(),
            ]);

        $this->logOccurrence($uuid, 'Scheduled occurrence failed', 'warning');
    }

    /**
     * Log one step of an occurrence. Search scheduled.log for its schedule_key to see when it
     * was due, when a worker started it, and how it ended.
     *
     * @param  array<string, mixed>  $context
     */
    private function logOccurrence(ScheduledJobDelivery|string $occurrence, string $message, string $level = 'info', array $context = []): void
    {
        try {
            $uuid = is_string($occurrence) ? $occurrence : $occurrence->uuid;
            if (is_string($occurrence)) {
                $occurrence = ScheduledJobDelivery::query()->where('uuid', $uuid)->first();
            }

            Log::channel('scheduled')->log($level, $message, [
                'occurrence_uuid' => $uuid,
                'schedule_key' => $occurrence?->schedule_key,
                'status' => $occurrence?->status,
                'scheduled_for' => $occurrence?->scheduled_for?->toIso8601String(),
                'enqueued_at' => $occurrence?->enqueued_at?->toIso8601String(),
                'started_at' => $occurrence?->started_at?->toIso8601String(),
                'seconds_since_due' => $occurrence ? (int) $occurrence->scheduled_for->diffInSeconds(now()) : null,
                'host' => gethostname(),
                'pid' => getmypid(),
                ...$context,
            ]);
        } catch (\Throwable) {
            // Debug logging must never change the job result.
        }
    }

    private function publish(ScheduledJobDelivery $occurrence): bool
    {
        if ($occurrence->status !== 'pending') {
            return false;
        }

        $job = match ($occurrence->job_type) {
            'scheduled-task' => ($task = ScheduledTask::find($occurrence->resource_id))
                ? new ScheduledTaskJob($task, $occurrence->uuid)
                : null,
            'database-backup' => ($backup = ScheduledDatabaseBackup::find($occurrence->resource_id))
                ? new DatabaseBackupJob($backup, $occurrence->uuid)
                : null,
            'volume-backup' => ($backup = ScheduledVolumeBackup::find($occurrence->resource_id))
                ? new VolumeBackupJob($backup, $occurrence->uuid)
                : null,
            'docker-cleanup' => ($server = Server::find($occurrence->resource_id))
                ? new DockerCleanupJob(
                    $server,
                    false,
                    data_get($occurrence->payload, 'delete_unused_volumes', false),
                    data_get($occurrence->payload, 'delete_unused_networks', false),
                    $occurrence->uuid,
                )
                : null,
            default => null,
        };

        if ($job === null) {
            ScheduledJobDelivery::query()->whereKey($occurrence->id)->update(['status' => 'skipped']);
            Log::channel('scheduled')->warning('Scheduled occurrence skipped: resource not found', [
                'occurrence_uuid' => $occurrence->uuid,
                'schedule_key' => $occurrence->schedule_key,
                'scheduled_for' => $occurrence->scheduled_for->toIso8601String(),
            ]);

            return false;
        }

        // An open previous occurrence usually means the previous run is still running. Backup jobs
        // then skip this one without running it (see withoutOverlapping()).
        $previousOpen = ScheduledJobDelivery::query()
            ->where('schedule_key', $occurrence->schedule_key)
            ->where('scheduled_for', '<', $occurrence->scheduled_for)
            ->whereIn('status', ['pending', 'enqueued', 'claimed'])
            ->orderByDesc('scheduled_for')
            ->first(['uuid', 'status', 'scheduled_for']);

        dispatch($job);

        ScheduledJobDelivery::query()
            ->whereKey($occurrence->id)
            ->where('status', 'pending')
            ->update([
                'status' => 'enqueued',
                'enqueued_at' => now(),
                'updated_at' => now(),
            ]);

        // Log from the loaded row: a fast worker can complete and delete the occurrence already.
        $enqueued = ['status' => 'enqueued', 'enqueued_at' => now()->toIso8601String()];
        if ($previousOpen) {
            $this->logOccurrence($occurrence, 'Scheduled occurrence enqueued while the previous occurrence is still open', 'warning', [
                ...$enqueued,
                'previous_occurrence_uuid' => $previousOpen->uuid,
                'previous_status' => $previousOpen->status,
                'previous_scheduled_for' => $previousOpen->scheduled_for->toIso8601String(),
            ]);
        } else {
            $this->logOccurrence($occurrence, 'Scheduled occurrence enqueued', context: $enqueued);
        }

        return true;
    }
}
