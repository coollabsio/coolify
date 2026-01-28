<?php

namespace App\Actions\Database\PgBackRest;

use App\Models\S3Storage;
use App\Models\Server;
use App\Models\StandalonePostgresql;
use Lorisleiva\Actions\Concerns\AsAction;

class SetupPgBackRest
{
    use AsAction;

    public function handle(
        StandalonePostgresql $database,
        Server $server,
        string $containerName,
    ): array {
        $stanza = $database->pgbackrest_stanza ?? 'db-'.$database->uuid;

        // Step 1: Install pgBackRest if not present
        $this->installPgBackRest($server, $containerName);

        // Step 2: Generate pgbackrest.conf
        $config = $this->generateConfig($database, $stanza);
        $this->writeConfig($server, $containerName, $config);

        // Step 3: Create necessary directories
        $this->createDirectories($server, $containerName, $database);

        // Step 4: Configure WAL archiving in PostgreSQL
        $this->configureWalArchiving($server, $containerName, $stanza, $database);

        // Step 5: Create stanza
        $this->createStanza($server, $containerName, $stanza);

        // Step 6: Update database model
        $database->update([
            'pgbackrest_enabled' => true,
            'pgbackrest_stanza' => $stanza,
        ]);

        return [
            'success' => true,
            'stanza' => $stanza,
            'message' => 'pgBackRest setup completed successfully.',
        ];
    }

    private function installPgBackRest(Server $server, string $containerName): void
    {
        // Check if pgBackRest is already installed
        $checkCmd = "docker exec {$containerName} which pgbackrest 2>/dev/null || echo 'NOT_FOUND'";
        $result = instant_remote_process([$checkCmd], $server, false, false, null, disableMultiplexing: true);

        if (str($result)->contains('NOT_FOUND')) {
            // Install pgBackRest inside the container
            $installCommands = [
                "docker exec {$containerName} sh -c 'apt-get update -qq && apt-get install -y -qq pgbackrest > /dev/null 2>&1'",
            ];
            instant_remote_process($installCommands, $server, true, false, 120, disableMultiplexing: true);
        }
    }

    private function generateConfig(StandalonePostgresql $database, string $stanza): string
    {
        $retentionFull = $database->pgbackrest_retention_full ?? 2;
        $retentionDiff = $database->pgbackrest_retention_diff ?? 7;

        $config = "[global]\n";
        $config .= "compress-type=lz4\n";
        $config .= "compress-level=6\n";
        $config .= "process-max=2\n";
        $config .= "log-level-console=info\n";
        $config .= "log-level-file=detail\n";
        $config .= "repo1-retention-full={$retentionFull}\n";
        $config .= "repo1-retention-diff={$retentionDiff}\n";

        if ($database->pgbackrest_repo_type === 's3' && $database->pgbackrest_s3_storage_id) {
            $s3 = S3Storage::find($database->pgbackrest_s3_storage_id);
            if ($s3) {
                $config .= "repo1-type=s3\n";
                $config .= "repo1-s3-endpoint=".str($s3->endpoint)->replace('https://', '')->replace('http://', '')."\n";
                $config .= "repo1-s3-bucket={$s3->bucket}\n";
                $config .= "repo1-s3-key={$s3->key}\n";
                $config .= "repo1-s3-key-secret={$s3->secret}\n";
                $config .= "repo1-s3-region=".($s3->region ?: 'us-east-1')."\n";
                $config .= 'repo1-path='.($s3->path ?: '/backups')."/{$stanza}\n";

                // Use URI style if endpoint is not AWS
                if (! str($s3->endpoint)->contains('amazonaws.com')) {
                    $config .= "repo1-s3-uri-style=path\n";
                }
                // Verify TLS by default, but allow non-TLS endpoints
                if (str($s3->endpoint)->startsWith('http://')) {
                    $config .= "repo1-storage-verify-tls=n\n";
                }
            }
        } else {
            $config .= "repo1-path=/var/lib/pgbackrest\n";
        }

        // Stanza section
        $config .= "\n[{$stanza}]\n";
        $config .= "pg1-path=/var/lib/postgresql/data\n";

        return $config;
    }

    private function writeConfig(Server $server, string $containerName, string $config): void
    {
        $escapedConfig = escapeshellarg($config);
        $commands = [
            "docker exec {$containerName} sh -c 'mkdir -p /etc/pgbackrest'",
            "docker exec {$containerName} sh -c 'echo {$escapedConfig} > /etc/pgbackrest/pgbackrest.conf'",
            "docker exec {$containerName} sh -c 'chmod 640 /etc/pgbackrest/pgbackrest.conf'",
            "docker exec {$containerName} sh -c 'chown postgres:postgres /etc/pgbackrest/pgbackrest.conf'",
        ];
        instant_remote_process($commands, $server, true, false, null, disableMultiplexing: true);
    }

    private function createDirectories(Server $server, string $containerName, StandalonePostgresql $database): void
    {
        if ($database->pgbackrest_repo_type !== 's3') {
            $commands = [
                "docker exec {$containerName} sh -c 'mkdir -p /var/lib/pgbackrest && chown -R postgres:postgres /var/lib/pgbackrest'",
            ];
            instant_remote_process($commands, $server, true, false, null, disableMultiplexing: true);
        }

        // Create log directory
        $commands = [
            "docker exec {$containerName} sh -c 'mkdir -p /var/log/pgbackrest && chown -R postgres:postgres /var/log/pgbackrest'",
        ];
        instant_remote_process($commands, $server, true, false, null, disableMultiplexing: true);
    }

    private function configureWalArchiving(Server $server, string $containerName, string $stanza, StandalonePostgresql $database): void
    {
        // Check current archive_mode
        $checkCmd = "docker exec {$containerName} su - postgres -c \"psql -tAc \\\"SHOW archive_mode;\\\"\"";
        $currentMode = trim(instant_remote_process([$checkCmd], $server, false, false, null, disableMultiplexing: true));

        // Configure WAL archiving
        $archiveCommand = "pgbackrest --stanza={$stanza} archive-push %p";
        $escapedArchiveCommand = str_replace("'", "''", $archiveCommand);

        $sqlCommands = [
            "docker exec {$containerName} su - postgres -c \"psql -c \\\"ALTER SYSTEM SET archive_mode = 'on';\\\"\"",
            "docker exec {$containerName} su - postgres -c \"psql -c \\\"ALTER SYSTEM SET archive_command = '{$escapedArchiveCommand}';\\\"\"",
            "docker exec {$containerName} su - postgres -c \"psql -c \\\"ALTER SYSTEM SET wal_level = 'replica';\\\"\"",
        ];
        instant_remote_process($sqlCommands, $server, true, false, null, disableMultiplexing: true);

        // Reload configuration (archive_mode requires restart, but archive_command is immediate)
        $reloadCmd = "docker exec {$containerName} su - postgres -c \"psql -c \\\"SELECT pg_reload_conf();\\\"\"";
        instant_remote_process([$reloadCmd], $server, false, false, null, disableMultiplexing: true);

        // If archive_mode was 'off', a full restart is needed
        if ($currentMode === 'off') {
            // Restart PostgreSQL inside the container
            $restartCmd = "docker exec {$containerName} su - postgres -c \"pg_ctl restart -D /var/lib/postgresql/data -w -t 60\"";
            instant_remote_process([$restartCmd], $server, true, false, 120, disableMultiplexing: true);
        }
    }

    private function createStanza(Server $server, string $containerName, string $stanza): void
    {
        $cmd = "docker exec {$containerName} su - postgres -c \"pgbackrest stanza-create --stanza={$stanza}\"";
        instant_remote_process([$cmd], $server, true, false, 120, disableMultiplexing: true);
    }
}
