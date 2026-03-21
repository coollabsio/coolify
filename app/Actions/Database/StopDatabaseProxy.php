<?php

namespace App\Actions\Database;

use App\Events\DatabaseProxyStopped;
use App\Models\ServiceDatabase;
use App\Models\Server;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Traits\ResolvesDatabaseTeamId;
use App\Traits\ResolvesEdgeProxyServer;
use Lorisleiva\Actions\Concerns\AsAction;

class StopDatabaseProxy
{
    use AsAction;
    use ResolvesDatabaseTeamId;
    use ResolvesEdgeProxyServer;

    public string $jobQueue = 'high';

    public function handle(StandaloneRedis|StandalonePostgresql|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|ServiceDatabase|StandaloneDragonfly|StandaloneClickhouse $database)
    {
        $deploymentServer = data_get($database, 'destination.server');
        $uuid = $database->uuid;
        if ($database->getMorphClass() === \App\Models\ServiceDatabase::class) {
            $deploymentServer = data_get($database, 'service.destination.server') ?? data_get($database, 'service.server');
        }
        if (! $deploymentServer instanceof Server) {
            return;
        }

        $this->runRemoteCommands(["docker rm -f {$uuid}-proxy"], $deploymentServer, false);
        $edgeProxyServer = $this->resolveEdgeProxyServerForTeamId($this->resolveDatabaseTeamId($database));
        if ($edgeProxyServer instanceof Server && $edgeProxyServer->id !== $deploymentServer->id) {
            $this->runRemoteCommands(["docker rm -f {$uuid}-proxy"], $edgeProxyServer, false);
        }

        $database->save();

        $this->dispatchDatabaseProxyStoppedEvent($database);

    }

    protected function runRemoteCommands(array $commands, Server $server, bool $throwError = true): ?string
    {
        return instant_remote_process($commands, $server, $throwError);
    }

    protected function dispatchDatabaseProxyStoppedEvent(
        StandaloneRedis|StandalonePostgresql|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|ServiceDatabase|StandaloneDragonfly|StandaloneClickhouse $database
    ): void {
        $teamId = $this->resolveDatabaseTeamId($database);

        DatabaseProxyStopped::dispatch($teamId);
    }

    protected function resolveEdgeProxyServerForTeamId(?int $teamId): ?Server
    {
        return $this->resolveEdgeProxyServerByTeamId($teamId);
    }
}
