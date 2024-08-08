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
use Lorisleiva\Actions\Concerns\AsAction;

class StopDatabase
{
    use AsAction;

    public function handle(StandaloneRedis|StandalonePostgresql|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse $database)
    {
        $server = $database->destination->server;
        if (! $server->isFunctional()) {
            return 'Server is not functional';
        }

        instant_remote_process(command: ["docker stop --time=30 $database->uuid"], server: $server, throwError: false);
        instant_remote_process(command: ["docker rm $database->uuid"], server: $server, throwError: false);
        instant_remote_process(command: ["docker rm -f $database->uuid"], server: $server, throwError: false);

        if ($database->is_public) {
            StopDatabaseProxy::run($database);
        }
    }
}
