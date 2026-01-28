<?php

namespace App\Actions\Database\PgBackRest;

use App\Models\Server;
use App\Models\StandalonePostgresql;
use Lorisleiva\Actions\Concerns\AsAction;

class RunPgBackRestRestore
{
    use AsAction;

    public function handle(
        StandalonePostgresql $database,
        Server $server,
        string $containerName,
        bool $delta = true,
        ?string $targetTime = null,
        ?int $timeout = 7200,
    ): array {
        $stanza = $database->pgbackrest_stanza ?? 'db-'.$database->uuid;

        // Step 1: Stop PostgreSQL
        $this->stopPostgres($server, $containerName);

        try {
            // Step 2: Run restore
            $output = $this->runRestore($server, $containerName, $stanza, $delta, $targetTime, $timeout);

            // Step 3: Start PostgreSQL
            $this->startPostgres($server, $containerName);

            return [
                'success' => true,
                'output' => $output,
                'message' => 'Restore completed successfully.',
            ];
        } catch (\Throwable $e) {
            // Always try to restart PostgreSQL even if restore fails
            try {
                $this->startPostgres($server, $containerName);
            } catch (\Throwable $restartError) {
                // Log but don't override the original exception
            }

            throw $e;
        }
    }

    private function stopPostgres(Server $server, string $containerName): void
    {
        $cmd = "docker exec {$containerName} su - postgres -c \"pg_ctl stop -D /var/lib/postgresql/data -m fast -w -t 60\"";
        instant_remote_process([$cmd], $server, true, false, 120, disableMultiplexing: true);
    }

    private function startPostgres(Server $server, string $containerName): void
    {
        $cmd = "docker exec {$containerName} su - postgres -c \"pg_ctl start -D /var/lib/postgresql/data -w -t 60\"";
        instant_remote_process([$cmd], $server, true, false, 120, disableMultiplexing: true);
    }

    private function runRestore(Server $server, string $containerName, string $stanza, bool $delta, ?string $targetTime, ?int $timeout): string
    {
        $restoreArgs = "--stanza={$stanza}";

        if ($delta) {
            $restoreArgs .= ' --delta';
        }

        if ($targetTime) {
            // Point-in-Time Recovery
            $escapedTarget = escapeshellarg($targetTime);
            $restoreArgs .= " --type=time --target={$escapedTarget}";
        }

        $cmd = "docker exec {$containerName} su - postgres -c \"pgbackrest restore {$restoreArgs}\"";
        $output = instant_remote_process([$cmd], $server, true, false, $timeout, disableMultiplexing: true);

        return trim($output ?? '');
    }
}
