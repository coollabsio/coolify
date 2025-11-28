<?php

namespace App\Actions\Database;

use App\Actions\Server\CleanupDocker;
use App\Events\ServiceStatusChanged;
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

    public function handle(StandaloneRedis|StandalonePostgresql|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse $database, bool $dockerCleanup = true)
    {
        try {
            $server = $database->destination->server;
            if (! $server->isFunctional()) {
                return 'Server is not functional';
            }

            $this->stopContainer($database, $database->uuid, 30);

            if ($dockerCleanup) {
                CleanupDocker::dispatch($server, false, false);
            }

            if ($database->is_public) {
                StopDatabaseProxy::run($database);
            }

            return 'Database stopped successfully';
        } catch (\Exception $e) {
            return 'Database stop failed: '.$e->getMessage();
        } finally {
            ServiceStatusChanged::dispatch($database->environment->project->team->id);
        }

    }

    private function stopContainer($database, string $containerName, int $timeout = 30): void
    {
        $server = $database->destination->server;
        instant_remote_process(command: [
            "docker stop -t $timeout $containerName",
            "docker rm -f $containerName",
        ], server: $server, throwError: false);
    }
}
