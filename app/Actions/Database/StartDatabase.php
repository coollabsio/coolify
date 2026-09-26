<?php

namespace App\Actions\Database;

use App\Enums\ActivityTypes;
use App\Enums\ProcessStatus;
use App\Exceptions\DatabaseStartException;
use App\Jobs\DatabaseStartJob;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Support\DatabaseOperationReservation;
use App\Support\ResourceStartActivity;
use Lorisleiva\Actions\Concerns\AsAction;
use Lorisleiva\Actions\Decorators\JobDecorator;
use Spatie\Activitylog\Models\Activity;
use Throwable;

class StartDatabase
{
    use AsAction;

    public function configureJob(JobDecorator $job): void
    {
        $job->onQueue(deployment_queue());
    }

    /**
     * @param  string|null  $reservation  The token from reserveOperation(). The request that queued
     *                                    this action holds the reservation; this action releases it.
     */
    public function handle(StandaloneRedis|StandalonePostgresql|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse $database, ?string $reservation = null): Activity|string
    {
        $reservation ??= DatabaseOperationReservation::acquire($database->uuid);
        if ($reservation === null) {
            return ResourceStartActivity::DATABASE_OPERATION_IN_PROGRESS_MESSAGE;
        }

        try {
            return $this->queueStart($database, $reservation);
        } finally {
            DatabaseOperationReservation::release($database->uuid, $reservation);
        }
    }

    private function queueStart(StandaloneRedis|StandalonePostgresql|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse $database, string $reservation): Activity|string
    {
        $server = $database->destination->server;
        if (! $server->isFunctional()) {
            return 'Server is not functional';
        }
        $busyError = self::operationInProgressError($database, $reservation);
        if ($busyError !== null) {
            return $busyError;
        }
        $prerequisiteError = self::prerequisiteError($database);
        if ($prerequisiteError !== null) {
            return $prerequisiteError;
        }
        $database->update([
            'restart_count' => 0,
            'last_restart_at' => null,
            'last_restart_type' => null,
        ]);

        $activity = activity()
            ->withProperties([
                'server_uuid' => $server->uuid,
                'type' => ActivityTypes::INLINE->value,
                'type_uuid' => $database->uuid,
                'status' => ProcessStatus::QUEUED->value,
                'team_id' => $server->team_id,
                'operation' => ResourceStartActivity::DATABASE_START_OPERATION,
            ])
            ->performedOn($database)
            ->event(ActivityTypes::INLINE->value)
            ->log('[]');

        if ($activity === null) {
            return 'Database start could not be queued because activity logging is disabled.';
        }

        try {
            DatabaseStartJob::dispatch(
                $database->getMorphClass(),
                (int) $database->getKey(),
                (int) $database->team()->id,
                (int) $activity->getKey(),
                auth()->id(),
            );
        } catch (Throwable $e) {
            ResourceStartActivity::markFailed($activity, 'Database start could not be queued.');

            throw $e;
        }

        if ($database->is_public && $database->public_port) {
            StartDatabaseProxy::dispatch($database);
        }

        return $activity;
    }

    /**
     * Reserve a start or restart before it is queued, so that a parallel request cannot also
     * queue one before the queued action has created its activity. Pass the token to the
     * queued action, which releases the reservation.
     *
     * @return string|null The reservation token, or null when another operation is in progress.
     */
    public static function reserveOperation(StandaloneRedis|StandalonePostgresql|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse $database): ?string
    {
        $reservation = DatabaseOperationReservation::acquire($database->uuid);
        if ($reservation === null) {
            return null;
        }

        if (self::operationInProgressError($database, $reservation) !== null) {
            DatabaseOperationReservation::release($database->uuid, $reservation);

            return null;
        }

        return $reservation;
    }

    /**
     * Queue a start or restart action with a reservation. Release the reservation if the
     * action cannot be queued, because then no action will release it.
     *
     * @param  class-string<StartDatabase|RestartDatabase>  $action
     */
    public static function dispatchReserved(string $action, StandaloneRedis|StandalonePostgresql|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse $database, string $reservation): void
    {
        try {
            $action::dispatch($database, $reservation);
        } catch (Throwable $e) {
            DatabaseOperationReservation::release($database->uuid, $reservation);

            throw $e;
        }
    }

    /**
     * Refuse a second start/restart while a start, restart or import of this database is
     * reserved, queued or running. The UI hides Start/Restart in this state; this is the
     * server-side check. Stale start activities do not block and are marked as failed, so
     * the UI stays consistent.
     *
     * @param  string|null  $reservation  The token of the caller's own reservation, which does not block.
     */
    public static function operationInProgressError(StandaloneRedis|StandalonePostgresql|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse $database, ?string $reservation = null): ?string
    {
        if (blank($database->uuid)) {
            return null;
        }

        if (DatabaseOperationReservation::isHeldByOther($database->uuid, $reservation)) {
            return ResourceStartActivity::DATABASE_OPERATION_IN_PROGRESS_MESSAGE;
        }

        $liveOperations = collect([
            ResourceStartActivity::DATABASE_START_OPERATION,
            ResourceStartActivity::DATABASE_IMPORT_OPERATION,
        ])->flatMap(fn (string $operation) => ResourceStartActivity::failStale(
            ResourceStartActivity::active($database->uuid, $operation)
        ));

        return $liveOperations->isNotEmpty() || ResourceStartActivity::latestRunning($database->uuid) !== null
            ? ResourceStartActivity::DATABASE_OPERATION_IN_PROGRESS_MESSAGE
            : null;
    }

    /**
     * Check prerequisites that would make the queued start fail, so the user gets the
     * error immediately instead of a queued start that can only fail later.
     */
    public static function prerequisiteError(StandaloneRedis|StandalonePostgresql|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse $database): ?string
    {
        if (! $database->enable_ssl) {
            return null;
        }

        if (! $database->destination->server->ensureCaCertificate()) {
            return DatabaseStartException::missingCaCertificate()->getMessage();
        }

        return null;
    }
}
