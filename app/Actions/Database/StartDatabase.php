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
            case \App\Models\StandalonePostgresql::class:
                $activity = StartPostgresql::run($database);
                break;
            case \App\Models\StandaloneRedis::class:
                $activity = StartRedis::run($database);
                break;
            case \App\Models\StandaloneMongodb::class:
                $activity = StartMongodb::run($database);
                break;
            case \App\Models\StandaloneMysql::class:
                $activity = StartMysql::run($database);
                break;
            case \App\Models\StandaloneMariadb::class:
                $activity = StartMariadb::run($database);
                break;
            case \App\Models\StandaloneKeydb::class:
                $activity = StartKeydb::run($database);
                break;
            case \App\Models\StandaloneDragonfly::class:
                $activity = StartDragonfly::run($database);
                break;
            case \App\Models\StandaloneClickhouse::class:
                $activity = StartClickhouse::run($database);
                break;
        }
        if ($database->is_public && $database->public_port) {
            StartDatabaseProxy::dispatch($database);
        }

        return $activity;
    }
}
