<?php

namespace App\Actions\Database;

use App\Models\ScheduledDatabaseBackup;
use App\Models\StandalonePostgresql;
use App\Services\Backup\PgBackrestService;
use Lorisleiva\Actions\Concerns\AsAction;

class PgBackrestRestore
{
    use AsAction;

    public function handle(
        StandalonePostgresql $database,
        ScheduledDatabaseBackup $backup,
        ?string $targetTime = null,
        ?string $backupLabel = null,
    ) {
        $server = $database->destination->server;
        $containerName = $database->uuid;

        $service = new PgBackrestService($database, $backup);

        $commands = [];

        // Write pgbackrest config into the container
        foreach ($service->getSetupCommands() as $cmd) {
            $commands[] = executeInDocker($containerName, $cmd);
        }

        // Stop the PostgreSQL process inside the container
        $commands[] = executeInDocker($containerName, 'pg_ctl stop -D /var/lib/postgresql/data -m fast 2>/dev/null || true');

        // Run the delta restore
        $restoreCommands = $service->getRestoreDatabaseCommand($targetTime, $backupLabel);
        foreach ($restoreCommands as $cmd) {
            // Skip the pg_ctl stop/start since we handle it ourselves
            if (str_contains($cmd, 'pg_ctl stop') || str_contains($cmd, 'pg_ctl start')) {
                continue;
            }
            $commands[] = executeInDocker($containerName, $cmd);
        }

        // Restart the container to bring PostgreSQL back cleanly
        $commands[] = "docker restart {$containerName}";
        $commands[] = "echo 'pgBackRest restore completed.'";

        return remote_process($commands, $server, callEventOnFinish: 'DatabaseStatusChanged');
    }
}
