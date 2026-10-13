<?php

namespace App\Support\DatabaseImport;

use App\Enums\ProcessStatus;
use App\Events\DatabaseImportFinished;
use App\Support\ResourceStartActivity;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Throwable;

/**
 * Cleanup of a database import: helper containers and temporary files on the server
 * and in the database container.
 *
 * The normal path sends the cleanup data through the encrypted CoolifyTask payload.
 * The import also stores the same data on its activity, so Coolify can stop and clean up
 * an import that it marks as failed after a restart or because it is stale.
 * The data has only ids, container names and paths. It must never have credentials.
 */
class DatabaseImportCleanup
{
    public const PROPERTY = 'cleanup';

    /**
     * Set on the activity when Coolify stopped the import. A retried CoolifyTask does not run it again.
     */
    public const STOP_REQUESTED_PROPERTY = 'stop_requested_at';

    /**
     * Why a failed import task was stopped. The activity gets this error when the stop has run.
     */
    public const STOP_REASON_PROPERTY = 'stop_reason';

    /**
     * Exit codes of the local SSH command that do not come from the restore: ssh exits with 255
     * when the connection fails and the local `timeout` wrapper exits with 124. The restore
     * command in the database container reports its own 124 and 255 as 1.
     *
     * @var array<int, string>
     */
    public const TRANSPORT_FAILURE_REASONS = [
        124 => 'The import timed out.',
        255 => 'The connection to the server was lost.',
    ];

    public const CLAIM_TTL_SECONDS = 7 * 24 * 3600;

    /**
     * The only keys that are stored on the activity and sent to the cleanup listener.
     */
    private const KEYS = [
        'container',
        'containerTmpPath',
        'scriptPath',
        'serverId',
        'operationUuid',
        'containerName',
        'serverTmpPath',
        'credentialTmpPath',
    ];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, int|string>
     */
    public static function storable(array $data): array
    {
        return array_filter(
            Arr::only($data, self::KEYS),
            fn ($value): bool => is_string($value) || is_int($value),
        );
    }

    /**
     * The cleanup data stored on an import activity, or null if the activity has no usable data.
     *
     * @return array<string, int|string>|null
     */
    public static function stored(Activity $activity): ?array
    {
        $data = data_get($activity, 'properties.'.self::PROPERTY);
        if (! is_array($data)) {
            return null;
        }

        $data = self::storable($data);
        if (! is_int($data['serverId'] ?? null) || ! Str::isUuid($data['operationUuid'] ?? null)) {
            return null;
        }

        return $data;
    }

    public static function stopRequested(Activity $activity): bool
    {
        return filled(data_get($activity, 'properties.'.self::STOP_REQUESTED_PROPERTY));
    }

    /**
     * Queue the stop of the remote restore and the cleanup. It does not run SSH commands
     * inline, so it is safe during boot. Errors are logged and do not stop the caller.
     *
     * @param  array<string, int|string>  $data
     */
    public static function queueStop(array $data): void
    {
        try {
            event(new DatabaseImportFinished([...$data, 'stopRestore' => true]));
        } catch (Throwable $exception) {
            Log::warning('Could not queue the stop of an interrupted database import', [
                'operationUuid' => $data['operationUuid'] ?? null,
                'serverId' => $data['serverId'] ?? null,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Stop a database import whose CoolifyTask failed (for example it timed out) before the import
     * reported its end, because the restore can still run in the database container.
     *
     * The activity stays in progress, so the import still blocks a start, restart, import or backup
     * of the database. The cleanup listener marks it as failed after the stop has run.
     *
     * @return bool True when the import is stopped this way; the caller must not mark the activity as failed.
     */
    public static function stopAfterTaskFailure(Activity $activity, string $reason): bool
    {
        if (data_get($activity, 'properties.operation') !== ResourceStartActivity::DATABASE_IMPORT_OPERATION) {
            return false;
        }

        $current = Activity::query()->find($activity->getKey());
        $status = data_get($current, 'properties.status');
        if (! $current || ! in_array($status, [ProcessStatus::QUEUED->value, ProcessStatus::IN_PROGRESS->value], true)) {
            return false;
        }

        if (self::stopRequested($current)) {
            return true;
        }

        $cleanup = self::stored($current);
        if ($cleanup === null) {
            return false;
        }

        // Save the stop flag before the stop is queued, so the listener and a retried CoolifyTask see it.
        $current->properties = $current->properties->merge([
            self::STOP_REQUESTED_PROPERTY => now()->toIso8601String(),
            self::STOP_REASON_PROPERTY => $reason,
        ]);
        $current->save();
        $activity->properties = $current->properties;

        self::queueStop($cleanup);

        return true;
    }

    /**
     * Stop a database import whose SSH command ended without the result of the restore (the
     * connection was lost or the local timeout ended it). The restore runs with `docker exec`
     * and does not stop with the SSH session, so it is handled like a failed task.
     *
     * @return bool True when the import is stopped this way; the caller must keep it in progress.
     */
    public static function stopAfterTransportFailure(Activity $activity, ?int $exitCode): bool
    {
        $reason = self::TRANSPORT_FAILURE_REASONS[$exitCode] ?? null;

        return $reason !== null && self::stopAfterTaskFailure($activity, $reason);
    }

    /**
     * Mark the imports of an operation as failed that wait for the stop of their failed task.
     * Called by the cleanup listener after the stop has run.
     */
    public static function finishStop(string $operationUuid): void
    {
        Activity::query()
            ->where('properties->operation_uuid', $operationUuid)
            ->where('properties->operation', ResourceStartActivity::DATABASE_IMPORT_OPERATION)
            ->whereIn('properties->status', [ProcessStatus::QUEUED->value, ProcessStatus::IN_PROGRESS->value])
            ->get()
            ->filter(fn (Activity $activity): bool => filled(data_get($activity, 'properties.'.self::STOP_REASON_PROPERTY)))
            ->each(fn (Activity $activity) => ResourceStartActivity::markFailed(
                $activity,
                data_get($activity, 'properties.'.self::STOP_REASON_PROPERTY).' '.ResourceStartActivity::IMPORT_STOPPED_MESSAGE,
            ));
    }

    public static function claimKey(string $operationUuid): string
    {
        return "database-import-cleanup:{$operationUuid}";
    }

    /**
     * Only the first caller for an operation gets true, so the cleanup runs at most once per import.
     */
    public static function claim(string $operationUuid): bool
    {
        return Cache::add(self::claimKey($operationUuid), true, self::CLAIM_TTL_SECONDS);
    }

    /**
     * Release the claim after a failed attempt, so a retry of the cleanup can run.
     */
    public static function release(string $operationUuid): void
    {
        Cache::forget(self::claimKey($operationUuid));
    }
}
