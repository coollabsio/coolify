<?php

namespace App\Support\DatabaseImport;

use App\Events\DatabaseImportFinished;
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
