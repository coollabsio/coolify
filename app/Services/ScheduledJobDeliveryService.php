<?php

namespace App\Services;

use App\Jobs\DatabaseBackupJob;
use App\Jobs\DockerCleanupJob;
use App\Jobs\ScheduledTaskJob;
use App\Jobs\VolumeBackupJob;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledJobDelivery;
use App\Models\ScheduledTask;
use App\Models\ScheduledVolumeBackup;
use App\Models\Server;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

class ScheduledJobDeliveryService
{
    /**
     * Record a pending delivery for one occurrence of a schedule. Returns null when the
     * occurrence already has a delivery.
     *
     * @param  array<string, mixed>  $payload
     */
    public function create(string $scheduleKey, CarbonInterface $scheduledFor, string $jobType, int $resourceId, array $payload = []): ?ScheduledJobDelivery
    {
        $delivery = ScheduledJobDelivery::createOrFirst(
            ['schedule_key' => $scheduleKey, 'scheduled_for' => $scheduledFor->utc()],
            ['job_type' => $jobType, 'resource_id' => $resourceId, 'payload' => $payload, 'status' => 'pending'],
        );

        return $delivery->wasRecentlyCreated ? $delivery : null;
    }

    public function publishPending(): void
    {
        ScheduledJobDelivery::query()
            ->where('status', 'pending')
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

    public function publish(ScheduledJobDelivery $occurrence): bool
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
        $this->logOccurrence($occurrence, 'Scheduled occurrence enqueued', context: ['status' => 'enqueued', 'enqueued_at' => now()->toIso8601String()]);

        return true;
    }
}
