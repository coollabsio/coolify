<?php

namespace App\Services\Backup;

use App\Models\S3Storage;
use App\Models\Server;
use App\Models\StandalonePostgresql;

class PgBackrestService
{
    public function __construct(
        private StandalonePostgresql $database,
        private Server $server,
    ) {}

    /**
     * Generate pgBackRest configuration file content.
     */
    public function generateConfig(?S3Storage $s3 = null): string
    {
        $stanza = $this->getStanzaName();
        $config = "[global]\n";
        $config .= "repo1-retention-full=2\n";
        $config .= "repo1-retention-diff=7\n";
        $config .= "log-level-console=info\n";
        $config .= "log-level-file=detail\n";
        $config .= "start-fast=y\n";
        $config .= "stop-auto=y\n";
        $config .= "delta=y\n";
        $config .= "compress-type=zst\n";
        $config .= "compress-level=3\n";

        if ($s3) {
            $config .= "repo1-type=s3\n";
            $config .= "repo1-s3-bucket={$s3->bucket}\n";
            $config .= "repo1-s3-endpoint={$s3->endpoint}\n";
            $config .= "repo1-s3-region={$s3->region}\n";
            $config .= "repo1-s3-key={$s3->key}\n";
            $config .= "repo1-s3-key-secret={$s3->secret}\n";
            $config .= "repo1-s3-uri-style=path\n";
            $config .= "repo1-path=/pgbackrest/{$stanza}\n";
        } else {
            $config .= "repo1-type=posix\n";
            $config .= "repo1-path=/var/lib/pgbackrest\n";
        }

        $config .= "\n[{$stanza}]\n";
        $config .= "pg1-path=/var/lib/postgresql/data\n";
        $config .= "pg1-port=5432\n";
        $config .= "pg1-user={$this->database->postgres_user}\n";

        return $config;
    }

    public function generateInstallScript(): string
    {
        $stanza = $this->getStanzaName();

        return <<<BASH
#!/bin/bash
set -e

# Install pgBackRest if not already present
if ! command -v pgbackrest &> /dev/null; then
    echo "Installing pgBackRest..."
    apt-get update -qq && apt-get install -y -qq pgbackrest > /dev/null 2>&1
    if ! command -v pgbackrest &> /dev/null; then
        echo "ERROR: pgBackRest installation failed"
        exit 1
    fi
    echo "pgBackRest installed successfully"
fi

# Ensure directories exist with correct ownership
mkdir -p /var/lib/pgbackrest /var/log/pgbackrest /etc/pgbackrest
chown -R postgres:postgres /var/lib/pgbackrest /var/log/pgbackrest /etc/pgbackrest || true

echo "pgBackRest setup complete"
BASH;
    }

    public function buildStanzaCreateCommand(): string
    {
        $stanza = $this->getStanzaName();

        return "pgbackrest --stanza={$stanza} stanza-create";
    }

    public function buildBackupCommand(string $type = 'incr'): string
    {
        $stanza = $this->getStanzaName();
        $validTypes = ['full', 'incr', 'diff'];
        $type = in_array($type, $validTypes) ? $type : 'incr';

        return "pgbackrest --stanza={$stanza} --type={$type} backup";
    }

    public function buildInfoCommand(): string
    {
        $stanza = $this->getStanzaName();

        return "pgbackrest --stanza={$stanza} --output=json info";
    }

    public function buildRestoreCommand(?string $targetTime = null): string
    {
        $stanza = $this->getStanzaName();
        $cmd = "pgbackrest --stanza={$stanza} --delta restore";

        if ($targetTime) {
            $cmd .= " --type=time --target=" . escapeshellarg($targetTime);
        }

        return $cmd;
    }

    public function buildExpireCommand(): string
    {
        $stanza = $this->getStanzaName();

        return "pgbackrest --stanza={$stanza} expire";
    }

    public function getWalArchiveParams(): array
    {
        $stanza = $this->getStanzaName();

        return [
            '-c', 'wal_level=replica',
            '-c', 'archive_mode=on',
            '-c', "archive_command=pgbackrest --stanza={$stanza} archive-push %p",
            '-c', 'max_wal_senders=3',
        ];
    }

    public function getStanzaName(): string
    {
        return 'db-' . $this->database->uuid;
    }

    public function getConfigDir(): string
    {
        return database_configuration_dir() . '/' . $this->database->uuid . '/pgbackrest';
    }

    /**
     * Initialize pgBackRest inside the running container.
     * Returns the commands to execute on the remote server.
     */
    public function buildSetupCommands(?S3Storage $s3 = null): array
    {
        $containerName = $this->database->uuid;
        $configContent = base64_encode($this->generateConfig($s3));
        $installScript = base64_encode($this->generateInstallScript());
        $configDir = $this->getConfigDir();

        $commands = [];

        $commands[] = "mkdir -p {$configDir}";
        $commands[] = "echo '{$configContent}' | base64 -d | tee {$configDir}/pgbackrest.conf > /dev/null";
        $commands[] = "echo '{$installScript}' | base64 -d | tee {$configDir}/install-pgbackrest.sh > /dev/null";
        $commands[] = "chmod +x {$configDir}/install-pgbackrest.sh";
        $commands[] = "docker cp {$configDir}/pgbackrest.conf {$containerName}:/etc/pgbackrest/pgbackrest.conf";
        $commands[] = "docker cp {$configDir}/install-pgbackrest.sh {$containerName}:/tmp/install-pgbackrest.sh";
        $commands[] = "docker exec {$containerName} bash /tmp/install-pgbackrest.sh";
        $commands[] = "docker exec -u postgres {$containerName} " . $this->buildStanzaCreateCommand() . " 2>&1 || true";
        $commands[] = "docker exec -u postgres {$containerName} pgbackrest --stanza=" . $this->getStanzaName() . " check";

        return $commands;
    }

    public function buildContainerBackupCommands(string $type = 'incr'): array
    {
        $containerName = $this->database->uuid;

        return [
            "docker exec -u postgres {$containerName} " . $this->buildBackupCommand($type),
        ];
    }

    public function buildContainerInfoCommands(): array
    {
        $containerName = $this->database->uuid;

        return [
            "docker exec -u postgres {$containerName} " . $this->buildInfoCommand(),
        ];
    }
}
