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
use Lorisleiva\Actions\Concerns\AsAction;

class StopDatabaseProxy
{
    use AsAction;

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

        $this->dispatchDatabaseProxyStoppedEvent();

    }

    protected function runRemoteCommands(array $commands, Server $server, bool $throwError = true): ?string
    {
        return instant_remote_process($commands, $server, $throwError);
    }

    protected function dispatchDatabaseProxyStoppedEvent(): void
    {
        DatabaseProxyStopped::dispatch();
    }

    protected function resolveEdgeProxyServerForTeamId(?int $teamId): ?Server
    {
        if (is_null($teamId)) {
            return null;
        }

        return Server::query()
            ->where('team_id', $teamId)
            ->whereRelation('settings', 'is_master_domain_router_enabled', true)
            ->orderBy('id')
            ->first();
    }

    private function resolveDatabaseTeamId(StandaloneRedis|StandalonePostgresql|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|ServiceDatabase|StandaloneDragonfly|StandaloneClickhouse $database): ?int
    {
        if ($database->getMorphClass() === \App\Models\ServiceDatabase::class) {
            $teamId = data_get($database, 'service.environment.project.team_id');
            if (! is_null($teamId)) {
                return (int) $teamId;
            }
        }

        $teamId = data_get($database, 'environment.project.team_id');
        if (! is_null($teamId)) {
            return (int) $teamId;
        }

        $teamId = data_get($database, 'team.id');
        if (! is_null($teamId)) {
            return (int) $teamId;
        }

        return null;
    }
}
