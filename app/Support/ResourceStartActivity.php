<?php

namespace App\Support;

use App\Enums\ProcessStatus;
use App\Models\Service;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * State of the activity log rows that track database and service start/restart runs.
 *
 * A killed worker, a Coolify restart or a lost queue can leave such an activity queued or
 * in progress forever. Activities that have not progressed for longer than their job can
 * possibly run are treated as stale, so they no longer block Start/Restart in the UI.
 * Command output is saved to the activity as it arrives, so `updated_at` is the heartbeat.
 */
class ResourceStartActivity
{
    public const DATABASE_START_OPERATION = 'database-start';

    /**
     * A start that is still waiting in the queue after this long is treated as lost.
     */
    public const QUEUED_STALE_AFTER_SECONDS = 600;

    /**
     * DatabaseStartJob and CoolifyTask time out after 600 seconds; allow a margin on top.
     */
    public const IN_PROGRESS_STALE_AFTER_SECONDS = 900;

    public const INTERRUPTED_MESSAGE = 'Interrupted by a Coolify restart.';

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

        $staleAfterSeconds = data_get($activity, 'properties.status') === ProcessStatus::QUEUED->value
            ? self::QUEUED_STALE_AFTER_SECONDS
            : self::IN_PROGRESS_STALE_AFTER_SECONDS;

        return $lastProgressAt->lte(now()->subSeconds($staleAfterSeconds));
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
     * Fail database and service start activities left queued or in progress by a restart.
     * Runs during app:init, before any queue worker is started.
     */
    public static function failInterrupted(): int
    {
        $activities = Activity::query()
            ->whereIn('properties->status', self::ACTIVE_STATUSES)
            ->get();

        $serviceUuids = self::existingServiceUuids($activities);

        $interrupted = $activities->filter(
            fn (Activity $activity): bool => data_get($activity, 'properties.operation') === self::DATABASE_START_OPERATION
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
