<?php

namespace App\Services;

use App\Jobs\DatabaseBackupJob;
use App\Jobs\DockerCleanupJob;
use App\Jobs\ScheduledTaskJob;
use App\Jobs\VolumeBackupJob;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledJobDelivery;
use App\Models\ScheduledJobState;
use App\Models\ScheduledTask;
use App\Models\ScheduledVolumeBackup;
use App\Models\Server;
use Cron\CronExpression;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ScheduledJobDeliveryService
{
    public const CATCH_UP_WINDOW_MINUTES = 10;

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

    public function publishPending(): void
    {
        ScheduledJobDelivery::query()
            ->where('status', 'pending')
            ->orderBy('id')
            ->chunkById(100, function ($occurrences): void {
                foreach ($occurrences as $occurrence) {
                    $this->publish($occurrence);
                }
            });
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
            ]);

        if ($claimed === 1) {
            return true;
        }

        return ScheduledJobDelivery::query()
            ->where('uuid', $uuid)
            ->where('status', 'claimed')
            ->where('claim_token', $claimToken)
            ->exists();
    }

    public function complete(string $uuid, string $claimToken): void
    {
        ScheduledJobDelivery::query()
            ->where('uuid', $uuid)
            ->where('claim_token', $claimToken)
            ->delete();
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

            return false;
        }

        dispatch($job);

        ScheduledJobDelivery::query()
            ->whereKey($occurrence->id)
            ->where('status', 'pending')
            ->update([
                'status' => 'enqueued',
                'enqueued_at' => now(),
                'updated_at' => now(),
            ]);

        return true;
    }
}
