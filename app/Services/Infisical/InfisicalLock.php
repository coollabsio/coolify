<?php

namespace App\Services\Infisical;

use App\Models\InfisicalConnection;

/**
 * Decides whether a variable write is allowed.
 *
 * Enforcement lives in the Eloquent hooks of EnvironmentVariable and
 * SharedEnvironmentVariable rather than at call sites, because call-site
 * enforcement leaks: the preview-clone observer, the Redis username accessor
 * that writes on read, the parse() pipeline, the scheduled sync job, and
 * ServerTransferImporter's withoutEvents() all bypass it.
 *
 * Known limit: mass deletes via a relation query builder
 * ($resource->environment_variables()->delete()) bypass the deleting hook
 * entirely, because query-builder deletes fire no model events.
 * Resource-deletion cascades (forceDeleting on every Standalone* model,
 * DeleteService, Application's buildpack-switch cleanup) rely on this and are
 * intentionally unguarded — wrapping them in asSystem() would be a no-op that
 * falsely implies the hook was protecting something.
 *
 * The same limit cuts the other way on the HUMAN side, and that part is not
 * benign: every handleBulkSubmit() drops rows with a query-builder mass delete
 * too, so the bulk textarea could otherwise delete a locked team's variables.
 * The hook cannot close that, so each of those methods carries an explicit
 * armedForTeam() check at the top instead — SharedVariables\{Team\Index,
 * Project\Show, Environment\Show} and Project\Shared\EnvironmentVariable\All.
 * SharedVariables\Server\Show is deliberately NOT guarded: server-scoped
 * variables are out of scope per the spec and stay editable.
 * ServerTransferImporter is the same shape — it writes inside withoutEvents() —
 * and refuses explicitly in import() before its first write.
 *
 * Any NEW code path that needs to be blocked must delete through model
 * instances, not the builder.
 */
class InfisicalLock
{
    private static int $systemDepth = 0;

    private static int $pullDepth = 0;

    /**
     * Run a write as Coolify rather than as a human. Re-entrant, and restores
     * the previous depth even if the callback throws.
     */
    public static function asSystem(callable $write): mixed
    {
        self::$systemDepth++;

        try {
            return $write();
        } finally {
            self::$systemDepth--;
        }
    }

    /**
     * Run a write that came DOWN from Infisical.
     *
     * Implies asSystem — a pull is a system write — but is tracked separately
     * so the upward push hook can tell "Coolify generated this locally, push
     * it up" from "Infisical just told us this, do not echo it back". With a
     * single flag the two sync directions feed each other forever.
     */
    public static function asInfisicalPull(callable $write): mixed
    {
        self::$pullDepth++;

        try {
            return self::asSystem($write);
        } finally {
            self::$pullDepth--;
        }
    }

    public static function isSystemWrite(): bool
    {
        return self::$systemDepth > 0;
    }

    public static function isInfisicalPull(): bool
    {
        return self::$pullDepth > 0;
    }

    /**
     * Deliberately NOT cached.
     *
     * A process-static cache was tried and rejected: Horizon workers are
     * long-lived, so a worker that cached "team 7 => not armed" would keep
     * failing the lock OPEN for every queued write until the worker restarted.
     * A silently-open security control is worse than none. It also made the
     * test suite order-dependent, because RefreshDatabase restarts sqlite
     * rowids so nearly every test creates team id 1.
     *
     * The cost is one indexed EXISTS per variable save. That is cheap, and
     * `parse()` loops that write hundreds of rows are already doing far more
     * work per row.
     */
    public static function armedForTeam(?int $teamId): bool
    {
        if ($teamId === null) {
            return false;
        }

        return InfisicalConnection::query()
            ->where('team_id', $teamId)
            ->where('is_enabled', true)
            ->exists();
    }

    /**
     * Cheap global short-circuit for the guards.
     *
     * The EnvironmentVariable guard has to load the polymorphic owner and walk
     * environment -> project before it knows which team to ask about. On an
     * instance with no enabled connection anywhere that is three wasted queries
     * on every single variable save, and parse() writes hundreds per deploy.
     * One indexed EXISTS answers it instead.
     *
     * Not a cache — it is queried every time, so enabling a connection arms the
     * lock within the same request, and it can only ever be a superset of
     * armedForTeam(): if it is false, no team has an enabled connection.
     */
    public static function anyConnectionEnabled(): bool
    {
        return InfisicalConnection::query()
            ->where('is_enabled', true)
            ->exists();
    }

    /**
     * Test seam only. A leaked depth would silently disarm the lock for the
     * rest of the run and make every "rejects" test pass meaninglessly.
     */
    public static function resetDepthForTesting(): void
    {
        self::$systemDepth = 0;
        self::$pullDepth = 0;
    }
}
