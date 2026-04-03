<?php

namespace App\Services\Backup;

use App\Models\S3Storage;

class PgBackrestService
{
    public static function generateConfig(
        string $stanzaName,
        string $pgDataPath = '/var/lib/postgresql/data',
        ?S3Storage $s3 = null
    ): string {
        $config = "[{$stanzaName}]\n";
        $config .= "pg1-path={$pgDataPath}\n\n";

        $config .= "[global]\n";
        $config .= "repo1-retention-full=2\n";
        $config .= "repo1-retention-diff=7\n";
        $config .= "log-level-console=info\n";
        $config .= "log-level-file=detail\n";

        if ($s3) {
            $config .= "repo1-type=s3\n";
            $config .= "repo1-s3-endpoint=" . $s3->endpoint . "\n";
            $config .= "repo1-s3-bucket=" . $s3->bucket . "\n";
            $config .= "repo1-s3-region=" . ($s3->region ?: 'us-east-1') . "\n";
            $config .= "repo1-s3-key=" . $s3->key . "\n";
            $config .= "repo1-s3-key-secret=" . $s3->secret . "\n";
            $config .= "repo1-path=/pgbackrest/{$stanzaName}\n";
        } else {
            $config .= "repo1-type=posix\n";
            $config .= "repo1-path=/var/lib/pgbackrest\n";
        }

        return $config;
    }

    public static function getSetupCommands(
        string $containerName,
        string $stanzaName
    ): array {
        return [
            "docker exec {$containerName} bash -c 'mkdir -p /etc/pgbackrest /var/lib/pgbackrest /var/log/pgbackrest'",
        ];
    }

    public static function getStanzaCreateCommand(
        string $containerName,
        string $stanzaName,
        string $postgresUser
    ): string {
        return "docker exec -u {$postgresUser} {$containerName} pgbackrest --stanza={$stanzaName} stanza-create";
    }

    public static function getBackupCommand(
        string $containerName,
        string $stanzaName,
        string $postgresUser,
        string $backupType = 'full'
    ): string {
        $validTypes = ['full', 'diff', 'incr'];
        if (! in_array($backupType, $validTypes)) {
            $backupType = 'full';
        }

        return "docker exec -u {$postgresUser} {$containerName} pgbackrest --stanza={$stanzaName} --type={$backupType} backup";
    }

    public static function getInfoCommand(
        string $containerName,
        string $stanzaName,
        string $postgresUser
    ): string {
        return "docker exec -u {$postgresUser} {$containerName} pgbackrest --stanza={$stanzaName} --output=json info";
    }

    public static function getWalArchiveCommand(string $stanzaName): string
    {
        return "pgbackrest --stanza={$stanzaName} archive-push %p";
    }

    public static function getPostgresWalConfig(string $stanzaName): array
    {
        return [
            '-c', 'wal_level=replica',
            '-c', 'archive_mode=on',
            '-c', "archive_command=pgbackrest --stanza={$stanzaName} archive-push %p",
            '-c', 'max_wal_senders=3',
        ];
    }

    public static function isPgBackrestAvailable(string $containerName): bool
    {
        try {
            $output = instant_remote_process(
                ["docker exec {$containerName} which pgbackrest 2>/dev/null && echo 'available' || echo 'not_available'"],
                null,
                true
            );

            return str_contains(trim($output), 'available');
        } catch (\Throwable) {
            return false;
        }
    }
}
