<?php

namespace App\Support;

use App\Enums\ProcessStatus;
use App\Models\Service;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * State of the activity log rows that track database and service start/restart runs
 * and database imports.
 *
 * A killed worker, a Coolify restart or a lost queue can leave such an activity queued or
 * in progress forever. Activities that have not progressed for longer than their job can
 * possibly run are treated as stale, so they no longer block Start/Restart/Import.
 * Command output is saved to the activity as it arrives, so `updated_at` is the heartbeat.
 */
class ResourceStartActivity
{
    public const DATABASE_START_OPERATION = 'database-start';

    public const DATABASE_IMPORT_OPERATION = 'database_import';

    /**
     * A start that is still waiting in the queue after this long is treated as lost.
     */
    public const QUEUED_STALE_AFTER_SECONDS = 600;

    /**
     * DatabaseStartJob and CoolifyTask time out after 600 seconds; allow a margin on top.
     */
    public const IN_PROGRESS_STALE_AFTER_SECONDS = 900;

    /**
     * A restore can be silent for a long time (for example pg_restore without verbose output),
     * so the output heartbeat is not reliable for imports. The remote restore process is killed
     * after the SSH command timeout, so an import without progress for longer than that plus
     * this margin cannot still be running.
     */
    public const IMPORT_STALE_MARGIN_SECONDS = 1800;

    /**
     * Lower bound for the import limit, also used when the SSH command timeout is disabled (0).
     */
    public const IMPORT_MIN_STALE_AFTER_SECONDS = 7200;

    /**
     * The start lock must not outlive a killed job for long: DatabaseStartJob times out after 600 seconds.
     */
    public const DATABASE_START_LOCK_SECONDS = self::IN_PROGRESS_STALE_AFTER_SECONDS;

    public const INTERRUPTED_MESSAGE = 'Interrupted by a Coolify restart.';

    public const STALE_MESSAGE = 'Marked as failed: no progress was recorded for too long.';

    public const SUPERSEDED_MESSAGE = 'Skipped: a newer start of this database was requested.';

    public const ALREADY_STARTING_MESSAGE = 'Skipped: another start of this database is still running.';

    public const DATABASE_OPERATION_IN_PROGRESS_MESSAGE = 'Another start, restart or import of this database is already in progress.';

    private const ACTIVE_STATUSES = [
        ProcessStatus::QUEUED->value,
        ProcessStatus::IN_PROGRESS->value,
    ];

    /**
     * The latest activity for a resource, if it is queued or in progress and not stale.
     */
    public static function latestRunning(string $typeUuid): ?Activity
    {
        $activity = Activity::query()
            ->where('properties->type_uuid', $typeUuid)
            ->latest()
            ->first();

        return self::isRunning($activity) ? $activity : null;
    }

    public static function isRunning(?Activity $activity): bool
    {
        if (! $activity || ! in_array(data_get($activity, 'properties.status'), self::ACTIVE_STATUSES, true)) {
            return false;
        }

        return ! self::isStale($activity);
    }

    public static function isStale(Activity $activity): bool
    {
        $lastProgressAt = $activity->updated_at ?? $activity->created_at;
        if (! $lastProgressAt) {
            return true;
        }

        $staleAfterSeconds = match (true) {
            data_get($activity, 'properties.operation') === self::DATABASE_IMPORT_OPERATION => self::importStaleAfterSeconds(),
            data_get($activity, 'properties.status') === ProcessStatus::QUEUED->value => self::QUEUED_STALE_AFTER_SECONDS,
            default => self::IN_PROGRESS_STALE_AFTER_SECONDS,
        };

        return $lastProgressAt->lte(now()->subSeconds($staleAfterSeconds));
    }

    public static function importStaleAfterSeconds(): int
    {
        $commandTimeout = (int) config('constants.ssh.command_timeout');

        return max(self::IMPORT_MIN_STALE_AFTER_SECONDS, $commandTimeout + self::IMPORT_STALE_MARGIN_SECONDS);
    }

    public static function databaseStartLockKey(string $databaseUuid): string
    {
        return "database-start:{$databaseUuid}";
    }

    /**
     * Queued or in-progress activities of one operation for a resource, oldest first.
     *
     * @return Collection<int, Activity>
     */
    public static function active(string $typeUuid, string $operation, ?int $teamId = null): Collection
    {
        return Activity::query()
            ->where('properties->type_uuid', $typeUuid)
            ->where('properties->operation', $operation)
            ->whereIn('properties->status', self::ACTIVE_STATUSES)
            ->when($teamId !== null, fn ($query) => $query->where('properties->team_id', $teamId))
            ->orderBy('id')
            ->get();
    }

    /**
     * Fail the stale activities in the list, so the UI and API stop reporting them as running.
     *
     * @param  Collection<int, Activity>  $activities
     * @return Collection<int, Activity> The activities that are not stale.
     */
    public static function failStale(Collection $activities): Collection
    {
        [$stale, $live] = $activities->partition(fn (Activity $activity): bool => self::isStale($activity));

        $stale->each(fn (Activity $activity) => self::markFailed($activity, self::STALE_MESSAGE));

        return $live->values();
    }

    /**
     * Mark an activity as failed, append the reason to its log and stop log polling.
     */
    public static function markFailed(Activity $activity, string $message): void
    {
        $properties = [
            'status' => ProcessStatus::ERROR->value,
            'error' => $message,
            'failed_at' => now()->toIso8601String(),
        ];
        if ($activity->properties->get('exitCode') === null) {
            $properties['exitCode'] = 1;
        }

        $activity->properties = $activity->properties->merge($properties);
        $activity->description = self::appendOutput($activity->description, $message);
        $activity->save();
    }

    /**
     * Fail database starts, database imports and service starts left queued or in progress
     * by a restart. Runs during app:init, before any queue worker is started.
     */
    public static function failInterrupted(): int
    {
        $activities = Activity::query()
            ->whereIn('properties->status', self::ACTIVE_STATUSES)
            ->get();

        $serviceUuids = self::existingServiceUuids($activities);

        $interrupted = $activities->filter(
            fn (Activity $activity): bool => in_array(
                data_get($activity, 'properties.operation'),
                [self::DATABASE_START_OPERATION, self::DATABASE_IMPORT_OPERATION],
                true,
            )
                || $serviceUuids->contains(data_get($activity, 'properties.type_uuid'))
        );

        $interrupted->each(fn (Activity $activity) => self::markFailed($activity, self::INTERRUPTED_MESSAGE));

        return $interrupted->count();
    }

    /**
     * @param  Collection<int, Activity>  $activities
     * @return Collection<int, string>
     */
    private static function existingServiceUuids(Collection $activities): Collection
    {
        $typeUuids = $activities
            ->map(fn (Activity $activity) => data_get($activity, 'properties.type_uuid'))
            ->filter(fn ($uuid): bool => is_string($uuid) && $uuid !== '')
            ->unique()
            ->values();

        if ($typeUuids->isEmpty()) {
            return collect();
        }

        return $typeUuids
            ->chunk(500)
            ->flatMap(fn (Collection $chunk) => Service::query()->whereIn('uuid', $chunk->all())->pluck('uuid'))
            ->values();
    }

    private static function appendOutput(?string $description, string $message): string
    {
        try {
            $entries = json_decode($description ?: '[]', true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $entries = [];
        }
        if (! is_array($entries)) {
            $entries = [];
        }

        $lastOrder = collect($entries)->max('order') ?? 0;
        $entries[] = [
            'type' => 'stderr',
            'output' => "\n{$message}\n",
            'timestamp' => hrtime(true),
            'batch' => 1,
            'order' => $lastOrder + 1,
        ];

        return json_encode($entries, flags: JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
