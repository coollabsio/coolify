<?php

namespace App\Actions\Database;

use App\Actions\Server\CleanupDocker;
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

    public function handle(StandaloneRedis|StandalonePostgresql|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse $database, bool $isDeleteOperation = false, bool $dockerCleanup = true)
    {
        $server = $database->destination->server;
        if (! $server->isFunctional()) {
            return 'Server is not functional';
        }

        $this->stopContainer($database, $database->uuid, 300);
        if ($isDeleteOperation) {
            if ($dockerCleanup) {
                CleanupDocker::dispatch($server, true);
            }
        }

        if ($database->is_public) {
            StopDatabaseProxy::run($database);
        }

        return 'Database stopped successfully';
    }

    private function stopContainer($database, string $containerName, int $timeout = 30): void
    {
        $server = $database->destination->server;
        instant_remote_process(command: [
            "docker stop --time=$timeout $containerName",
            "docker rm -f $containerName",
        ], server: $server, throwError: false);
    }
}
