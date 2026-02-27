<?php

namespace App\Services\Backup;

use App\Models\ScheduledDatabaseBackup;
use App\Models\StandalonePostgresql;

class PgBackrestService
{
    private string $pgDataPath;

    private string $stanza;

    public function __construct(
        private StandalonePostgresql $database,
        private ScheduledDatabaseBackup $backup
    ) {
        $this->pgDataPath = $this->detectPgDataPath();
        $this->stanza = $this->backup->pgbackrest_stanza ?? ('db-'.$this->database->uuid);
    }

    public function generatePgBackrestConf(): string
    {
        $conf = [];
        $conf[] = "[{$this->stanza}]";
        $conf[] = "pg1-path={$this->pgDataPath}";
        $conf[] = '';

        $conf[] = '[global]';

        if ($this->backup->pgbackrest_repo_type === 's3') {
            $conf[] = 'repo1-type=s3';
            $conf[] = "repo1-s3-bucket={$this->backup->pgbackrest_s3_bucket}";
            $conf[] = "repo1-s3-endpoint={$this->backup->pgbackrest_s3_endpoint}";
            $conf[] = "repo1-s3-region={$this->backup->pgbackrest_s3_region}";
            $conf[] = "repo1-s3-key={$this->backup->pgbackrest_s3_key}";
            $conf[] = "repo1-s3-key-secret={$this->backup->pgbackrest_s3_secret}";
            $conf[] = "repo1-path=/pgbackrest/{$this->database->uuid}";

            // Non-AWS S3 endpoints (MinIO, etc.) need path-style URIs and may need TLS off
            if ($this->isNonAwsEndpoint($this->backup->pgbackrest_s3_endpoint)) {
                $conf[] = 'repo1-s3-uri-style=path';
                if (str_starts_with($this->backup->pgbackrest_s3_endpoint, 'http://')) {
                    $conf[] = 'repo1-storage-verify-tls=n';
                }
            }
        } else {
            $conf[] = 'repo1-type=posix';
            $conf[] = "repo1-path=/var/lib/pgbackrest";
        }

        $conf[] = "repo1-retention-full={$this->backup->pgbackrest_retention_full}";
        $conf[] = "repo1-retention-diff={$this->backup->pgbackrest_retention_diff}";

        $compressType = $this->backup->pgbackrest_compress_type ?? 'gz';
        $compressLevel = $this->backup->pgbackrest_compress_level ?? 6;
        $conf[] = "compress-type={$compressType}";
        $conf[] = "compress-level={$compressLevel}";

        $conf[] = "log-level-console={$this->backup->pgbackrest_log_level_console}";
        $conf[] = "log-level-file={$this->backup->pgbackrest_log_level_file}";

        // Start output to /dev/null since pgBackRest manages its own log files
        $conf[] = 'log-path=/var/log/pgbackrest';

        $conf[] = '';

        return implode("\n", $conf);
    }

    public function getInstallCommands(): array
    {
        return [
            // Try apt-get first (Debian-based), then apk (Alpine)
            '(command -v pgbackrest > /dev/null 2>&1 && echo "pgBackRest already installed") || '.
            '(apt-get update -qq && apt-get install -y -qq pgbackrest > /dev/null 2>&1) || '.
            '(apk add --no-cache pgbackrest > /dev/null 2>&1)',
            'mkdir -p /var/log/pgbackrest',
            'mkdir -p /etc/pgbackrest',
            'mkdir -p /var/lib/pgbackrest',
            'chown -R postgres:postgres /var/log/pgbackrest /etc/pgbackrest /var/lib/pgbackrest',
        ];
    }

    public function getSetupCommands(): array
    {
        $confContent = $this->generatePgBackrestConf();
        $confBase64 = base64_encode($confContent);

        return [
            "echo '{$confBase64}' | base64 -d > /etc/pgbackrest/pgbackrest.conf",
            'chown postgres:postgres /etc/pgbackrest/pgbackrest.conf',
            'chmod 640 /etc/pgbackrest/pgbackrest.conf',
        ];
    }

    public function getStanzaCreateCommand(): string
    {
        return "su - postgres -c 'pgbackrest --stanza={$this->stanza} stanza-create' 2>&1 || true";
    }

    public function getStanzaCheckCommand(): string
    {
        return "su - postgres -c 'pgbackrest --stanza={$this->stanza} check' 2>&1";
    }

    public function getBackupCommand(): string
    {
        $type = $this->backup->pgbackrest_backup_type ?? 'full';

        return "su - postgres -c 'pgbackrest --stanza={$this->stanza} --type={$type} backup' 2>&1";
    }

    public function getInfoCommand(?string $outputFormat = 'json'): string
    {
        $formatArg = $outputFormat ? " --output={$outputFormat}" : '';

        return "su - postgres -c 'pgbackrest --stanza={$this->stanza}{$formatArg} info' 2>&1";
    }

    public function getExpireCommand(): string
    {
        return "su - postgres -c 'pgbackrest --stanza={$this->stanza} expire' 2>&1";
    }

    public function getRestoreDatabaseCommand(?string $targetTime = null, ?string $backupLabel = null): array
    {
        $commands = [];

        // Stop PostgreSQL
        $commands[] = 'pg_ctl stop -D '.$this->pgDataPath.' -m fast 2>/dev/null || true';

        // Build restore command
        $restore = "pgbackrest --stanza={$this->stanza} --delta";

        if ($backupLabel) {
            $restore .= " --set={$backupLabel}";
        }

        if ($targetTime) {
            $restore .= " --type=time --target=\"{$targetTime}\"";
        }

        $restore .= ' restore';

        $commands[] = "su - postgres -c '{$restore}' 2>&1";

        // Start PostgreSQL
        $commands[] = 'pg_ctl start -D '.$this->pgDataPath.' 2>&1';

        return $commands;
    }

    public function getWalArchivingParams(): array
    {
        return [
            '-c', 'archive_mode=on',
            '-c', "archive_command='pgbackrest --stanza={$this->stanza} archive-push %p'",
            '-c', 'wal_level=replica',
            '-c', 'max_wal_senders=3',
        ];
    }

    public function getDockerVolumes(): array
    {
        $volumes = [];

        if ($this->backup->pgbackrest_repo_type === 'posix') {
            $volumeName = $this->database->pgbackrestVolumeName();
            $volumes[] = "{$volumeName}:/var/lib/pgbackrest";
        }

        return $volumes;
    }

    public function getDockerVolumeDefinitions(): array
    {
        $definitions = [];

        if ($this->backup->pgbackrest_repo_type === 'posix') {
            $volumeName = $this->database->pgbackrestVolumeName();
            $definitions[$volumeName] = [
                'name' => $volumeName,
                'external' => false,
            ];
        }

        return $definitions;
    }

    public function parseBackupInfo(string $jsonOutput): array
    {
        $info = json_decode($jsonOutput, true);
        if (! $info || ! is_array($info)) {
            return [];
        }

        $result = [];
        foreach ($info as $stanzaInfo) {
            if (($stanzaInfo['name'] ?? '') !== $this->stanza) {
                continue;
            }
            foreach ($stanzaInfo['backup'] ?? [] as $backup) {
                $result[] = [
                    'label' => $backup['label'] ?? '',
                    'type' => $backup['type'] ?? '',
                    'timestamp_start' => $backup['timestamp']['start'] ?? null,
                    'timestamp_stop' => $backup['timestamp']['stop'] ?? null,
                    'database_size' => $backup['info']['size'] ?? 0,
                    'backup_size' => $backup['info']['delta'] ?? 0,
                    'repository_size' => $backup['info']['repository']['size'] ?? 0,
                    'repository_delta' => $backup['info']['repository']['delta'] ?? 0,
                    'wal_start' => $backup['lsn']['start'] ?? null,
                    'wal_stop' => $backup['lsn']['stop'] ?? null,
                ];
            }
        }

        return $result;
    }

    public function getStanza(): string
    {
        return $this->stanza;
    }

    private function detectPgDataPath(): string
    {
        $image = $this->database->image ?? '';
        $majorVersion = 0;

        if (preg_match('/:(?:pg)?(\d+)/i', $image, $matches)) {
            $majorVersion = (int) $matches[1];
        }

        return $majorVersion >= 18
            ? '/var/lib/postgresql'
            : '/var/lib/postgresql/data';
    }

    private function isNonAwsEndpoint(?string $endpoint): bool
    {
        if (blank($endpoint)) {
            return false;
        }

        return ! str_contains($endpoint, 'amazonaws.com');
    }
}
