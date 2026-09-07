<?php

namespace App\Support;

final class ClickhouseBackupCommand
{
    /** @return array<int, string> */
    public static function make(
        string $containerName,
        string $database,
        string $archiveName,
        string $backupDirectory,
    ): array {
        validateShellSafePath($database, 'database name');
        validateFilenameSafe($archiveName, 'ClickHouse backup archive');

        $backupDirectory = rtrim($backupDirectory, '/');
        $containerBackupPath = '/var/lib/clickhouse/backups/'.$archiveName;
        $backupLocation = $backupDirectory.'/'.$archiveName;
        $query = "BACKUP DATABASE `{$database}` TO File('{$archiveName}')";

        return [
            'mkdir -p '.escapeshellarg($backupDirectory),
            'docker exec '.escapeshellarg($containerName).' clickhouse-client --query '.escapeshellarg($query),
            'docker cp '.escapeshellarg($containerName.':'.$containerBackupPath).' '.escapeshellarg($backupLocation),
        ];
    }

    public static function cleanup(string $containerName, string $archiveName): string
    {
        validateFilenameSafe($archiveName, 'ClickHouse backup archive');

        $containerBackupPath = '/var/lib/clickhouse/backups/'.$archiveName;

        return 'docker exec '.escapeshellarg($containerName).' rm -f '.escapeshellarg($containerBackupPath);
    }
}
