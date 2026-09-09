<?php

namespace App\Actions\Database;

use App\Actions\Server\CleanupDocker;
use App\Events\ServiceStatusChanged;
use App\Models\BaseModel;
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

    public function handle(StandaloneRedis|StandalonePostgresql|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse $database, bool $dockerCleanup = true, bool $resetRestartCount = true, bool $removeContainer = true): string
    {
        try {
            $server = $database->destination->server;
            if (! $server->isFunctional()) {
                return 'Server is not functional';
            }

            $this->stopContainer($database, $database->uuid, 30, $removeContainer);

            // Reset restart tracking when database is manually stopped
            $database->update(['status' => 'exited']);
            if ($resetRestartCount) {
                $database->update([
                    'restart_count' => 0,
                    'last_restart_at' => null,
                    'last_restart_type' => null,
                ]);
            }

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

    private function stopContainer(BaseModel $database, string $containerName, int $timeout = 30, bool $removeContainer = true): void
    {
        $server = $database->destination->server;
        $commands = [dockerStopCommand($timeout, $containerName, $server)];
        if ($removeContainer) {
            $commands[] = "docker rm -f $containerName";
        }
        instant_remote_process(command: $commands, server: $server, throwError: false);
    }
}
