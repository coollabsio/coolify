<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Reserves a database for one start, restart or import between the request and the moment
 * the operation has created its activity.
 *
 * The API and MCP queue StartDatabase/RestartDatabase, and the queued action creates the
 * activity later. Without a reservation, two parallel requests both find no activity and
 * both get "queued". Cache::add() is atomic (SET NX on Redis), so only one request can
 * hold the reservation. The holder releases it when its activity exists or when it stops.
 * The TTL releases the reservation of a lost job; it is the same as the limit after which
 * a queued start activity is stale.
 */
class DatabaseOperationReservation
{
    public const TTL_SECONDS = ResourceStartActivity::QUEUED_STALE_AFTER_SECONDS;

    public static function key(string $databaseUuid): string
    {
        return "database-operation-pending:{$databaseUuid}";
    }

    /**
     * A database without a UUID (not saved) has nothing to reserve: the token is returned
     * without a cache entry, the same as operationInProgressError() does not block it.
     *
     * @return string|null The reservation token, or null when another operation holds the reservation.
     */
    public static function acquire(?string $databaseUuid): ?string
    {
        $token = (string) Str::uuid();
        if (blank($databaseUuid)) {
            return $token;
        }

        return Cache::add(self::key($databaseUuid), $token, self::TTL_SECONDS) ? $token : null;
    }

    /**
     * True when a reservation exists that does not belong to the given token.
     */
    public static function isHeldByOther(?string $databaseUuid, ?string $token = null): bool
    {
        if (blank($databaseUuid)) {
            return false;
        }

        $holder = Cache::get(self::key($databaseUuid));

        return $holder !== null && $holder !== $token;
    }

    /**
     * Release the reservation only if the token still holds it, so that an expired
     * reservation does not remove the reservation of a newer request.
     */
    public static function release(?string $databaseUuid, ?string $token): void
    {
        if ($token === null || blank($databaseUuid)) {
            return;
        }

        if (Cache::get(self::key($databaseUuid)) === $token) {
            Cache::forget(self::key($databaseUuid));
        }
    }
}
