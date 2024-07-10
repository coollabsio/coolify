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

class StartDatabase
{
    use AsAction;

    public function handle(StandaloneRedis|StandalonePostgresql|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse $database)
    {
        $server = $database->destination->server;
        if (! $server->isFunctional()) {
            return 'Server is not functional';
        }
        switch ($database->getMorphClass()) {
            case 'App\Models\StandalonePostgresql':
                $activity = StartPostgresql::run($database);
                break;
            case 'App\Models\StandaloneRedis':
                $activity = StartRedis::run($database);
                break;
            case 'App\Models\StandaloneMongodb':
                $activity = StartMongodb::run($database);
                break;
            case 'App\Models\StandaloneMysql':
                $activity = StartMysql::run($database);
                break;
            case 'App\Models\StandaloneMariadb':
                $activity = StartMariadb::run($database);
                break;
            case 'App\Models\StandaloneKeydb':
                $activity = StartKeydb::run($database);
                break;
            case 'App\Models\StandaloneDragonfly':
                $activity = StartDragonfly::run($database);
                break;
            case 'App\Models\StandaloneClickhouse':
                $activity = StartClickhouse::run($database);
                break;
        }
        if ($database->is_public && $database->public_port) {
            StartDatabaseProxy::dispatch($database);
        }

        return $activity;
    }
}
