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
use Illuminate\Support\Facades\Process;
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
        if (! $isDeleteOperation) {
            if ($dockerCleanup) {
                CleanupDocker::dispatch($server, true);
            }
        }

        if ($database->is_public) {
            StopDatabaseProxy::run($database);
        }

        return 'Database stopped successfully';
    }

    private function stopContainer($database, string $containerName, int $timeout = 300): void
    {
        $server = $database->destination->server;

        $process = Process::timeout($timeout)->start("docker stop --time=$timeout $containerName");

        $startTime = time();
        while ($process->running()) {
            if (time() - $startTime >= $timeout) {
                $this->forceStopContainer($containerName, $server);
                break;
            }
            usleep(100000);
        }

        $this->removeContainer($containerName, $server);
    }

    private function forceStopContainer(string $containerName, $server): void
    {
        instant_remote_process(command: ["docker kill $containerName"], server: $server, throwError: false);
    }

    private function removeContainer(string $containerName, $server): void
    {
        instant_remote_process(command: ["docker rm -f $containerName"], server: $server, throwError: false);
    }

    private function deleteConnectedNetworks($uuid, $server)
    {
        instant_remote_process(["docker network disconnect {$uuid} coolify-proxy"], $server, false);
        instant_remote_process(["docker network rm {$uuid}"], $server, false);
    }
}
