<?php

namespace App\Services;

use App\Events\DockerCleanupDone;
use App\Events\ScheduledTaskDone;
use App\Jobs\DatabaseBackupJob;
use App\Jobs\DockerCleanupJob;
use App\Jobs\ScheduledTaskJob;
use App\Jobs\VolumeBackupJob;
use App\Models\DockerCleanupExecution;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledJobDelivery;
use App\Models\ScheduledJobState;
use App\Models\ScheduledTask;
use App\Models\ScheduledTaskExecution;
use App\Models\ScheduledVolumeBackup;
use App\Models\Server;
use App\Models\Team;
use App\Notifications\ScheduledTask\TaskFailed;
use App\Notifications\Server\DockerCleanupFailed;
use Cron\CronExpression;
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
     * Job types that run once when they are late. A lost task or Docker cleanup is not run; it is
     * recorded as a failed execution and the team is notified.
     */
    private const LATE_RUN_JOB_TYPES = ['database-backup', 'volume-backup'];

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

        $delivery = DB::transaction(function () use ($scheduleKey, $scheduledFor, $jobType, $resourceId, $payload): ?ScheduledJobDelivery {
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

            if ($state->last_scheduled_for?->gte($scheduledFor)) {
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

        return DB::transaction(function () use ($scheduleKey, $scheduledFor): bool {
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

            if ($state->last_scheduled_for?->gte($scheduledFor)) {
                return false;
            }

            $state->update(['last_scheduled_for' => $scheduledFor->utc()]);

            return true;
        });
    }

    /**
     * @param  array<int, string>|null  $jobTypes  Only these job types, or all when null.
     */
    public function publishPending(?array $jobTypes = null): void
    {
        ScheduledJobDelivery::query()
            ->where('status', 'pending')
            ->when($jobTypes !== null, fn ($query) => $query->whereIn('job_type', $jobTypes))
            ->orderBy('id')
            ->chunkById(100, function ($occurrences): void {
                foreach ($occurrences as $occurrence) {
                    try {
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
            });
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
        ScheduledJobDelivery::query()
            ->where('status', 'enqueued')
            ->when($jobTypes !== null, fn ($query) => $query->whereIn('job_type', $jobTypes))
            ->where('enqueued_at', '<', now()->subMinutes(self::ENQUEUED_STALE_AFTER_MINUTES))
            ->chunkById(100, function ($occurrences): void {
                foreach ($occurrences as $occurrence) {
                    try {
                        $this->recoverStaleEnqueuedOccurrence($occurrence);
                    } catch (\Throwable $e) {
                        Log::channel('scheduled-errors')->error('Failed to recover enqueued scheduled occurrence', [
                            'occurrence_uuid' => $occurrence->uuid,
                            'schedule_key' => $occurrence->schedule_key,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });
    }

    private function recoverStaleEnqueuedOccurrence(ScheduledJobDelivery $occurrence): void
    {
        // complete() deletes the row of a finished occurrence, so the schedule state also counts.
        $hasNewerOccurrence = ScheduledJobState::query()
            ->where('schedule_key', $occurrence->schedule_key)
            ->where('last_scheduled_for', '>', $occurrence->scheduled_for)
            ->exists()
            || ScheduledJobDelivery::query()
                ->where('schedule_key', $occurrence->schedule_key)
                ->where('scheduled_for', '>', $occurrence->scheduled_for)
                ->exists();
        $runLate = in_array($occurrence->job_type, self::LATE_RUN_JOB_TYPES, true) && ! $hasNewerOccurrence;

        // Only one scheduler node can move each occurrence.
        $moved = ScheduledJobDelivery::query()
            ->whereKey($occurrence->id)
            ->where('status', 'enqueued')
            ->where('enqueued_at', '<', now()->subMinutes(self::ENQUEUED_STALE_AFTER_MINUTES))
            ->update(['status' => $runLate ? 'pending' : 'failed', 'updated_at' => now()]) === 1;

        if (! $moved) {
            return;
        }

        if (! $runLate) {
            $this->logOccurrence($occurrence->uuid, $hasNewerOccurrence
                ? 'Scheduled occurrence skipped: its queued job was not started and a newer occurrence exists'
                : 'Scheduled occurrence missed: its queued job was not started', 'warning');
            $this->reportMissedOccurrence($occurrence);

            return;
        }

        $occurrence->status = 'pending';
        $this->logOccurrence($occurrence->uuid, 'Scheduled occurrence publishing again: its queued job was not started', 'warning');
        $this->publish($occurrence);
    }

    /**
     * Show a missed task or Docker cleanup as a failed execution and send the failure notification.
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
                Team::find($task->team_id)?->notify(new TaskFailed($task, $message));
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
                $server->team?->notify(new DockerCleanupFailed($server, "Docker cleanup job failed with the following error: {$message}"));
            }
        } catch (\Throwable $e) {
            Log::channel('scheduled-errors')->error('Failed to report missed scheduled occurrence', [
                'occurrence_uuid' => $occurrence->uuid,
                'schedule_key' => $occurrence->schedule_key,
                'error' => $e->getMessage(),
            ]);
        }
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
