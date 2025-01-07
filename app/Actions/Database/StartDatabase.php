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

    public string $jobQueue = 'high';

    public function handle(StandaloneRedis|StandalonePostgresql|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse $database)
    {
        $server = $database->destination->server;
        if (! $server->isFunctional()) {
            return 'Server is not functional';
        }
        switch ($database->getMorphClass()) {
            case StandalonePostgresql::class:
                $activity = StartPostgresql::run($database);
                break;
            case StandaloneRedis::class:
                $activity = StartRedis::run($database);
                break;
            case StandaloneMongodb::class:
                $activity = StartMongodb::run($database);
                break;
            case StandaloneMysql::class:
                $activity = StartMysql::run($database);
                break;
            case StandaloneMariadb::class:
                $activity = StartMariadb::run($database);
                break;
            case StandaloneKeydb::class:
                $activity = StartKeydb::run($database);
                break;
            case StandaloneDragonfly::class:
                $activity = StartDragonfly::run($database);
                break;
            case StandaloneClickhouse::class:
                $activity = StartClickhouse::run($database);
                break;
        }
        if ($database->is_public && $database->public_port) {
            StartDatabaseProxy::dispatch($database);
        }

        return $activity;
    }
}
