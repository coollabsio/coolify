<?php

namespace App\Actions\Database;

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

class RestartDatabase
{
    use AsAction;

    /**
     * @param  string|null  $reservation  The token from StartDatabase::reserveOperation(). The request
     *                                    that queued this action holds the reservation; this action
     *                                    keeps it during the stop and releases it after the start.
     */
    public function handle(StandaloneRedis|StandalonePostgresql|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse $database, ?string $reservation = null)
    {
        $reservation ??= DatabaseOperationReservation::acquire($database->uuid);
        if ($reservation === null) {
            return ResourceStartActivity::DATABASE_OPERATION_IN_PROGRESS_MESSAGE;
        }

        try {
            $server = $database->destination->server;
            if (! $server->isFunctional()) {
                return 'Server is not functional';
            }
            $busyError = StartDatabase::operationInProgressError($database, $reservation);
            if ($busyError !== null) {
                return $busyError;
            }
            $prerequisiteError = StartDatabase::prerequisiteError($database);
            if ($prerequisiteError !== null) {
                return $prerequisiteError;
            }
            StopDatabase::run($database, dockerCleanup: false);

            return StartDatabase::run($database, $reservation);
        } finally {
            DatabaseOperationReservation::release($database->uuid, $reservation);
        }
    }
}
