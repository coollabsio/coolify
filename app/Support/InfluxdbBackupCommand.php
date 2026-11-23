<?php

namespace App\Support;

final class InfluxdbBackupCommand
{
    private const CONTAINER_BACKUP_ROOT = '/tmp/coolify-influxdb-backup';

    /**
     * InfluxDB 2.x writes a backup as a directory of files, so the dump is tarred inside the
     * container before it is copied out as one archive. The admin token is read from the
     * container environment, so it does not show in the process list of the host.
     *
     * @return array<int, string>
     */
    public static function make(
        string $containerName,
        string $bucket,
        string $archiveName,
        string $backupDirectory,
    ): array {
        validateShellSafePath($bucket, 'bucket name');
        validateFilenameSafe($archiveName, 'InfluxDB backup archive');

        $backupDirectory = rtrim($backupDirectory, '/');
        $workDir = self::workDir($archiveName);
        $containerArchivePath = self::containerArchivePath($archiveName);
        $exec = 'docker exec '.escapeshellarg($containerName);
        $backupScript = 'influx backup "$1" --host http://127.0.0.1:8086 --bucket "$2" --token "$DOCKER_INFLUXDB_INIT_ADMIN_TOKEN"';

        return [
            'mkdir -p '.escapeshellarg($backupDirectory),
            self::cleanup($containerName, $archiveName),
            $exec.' mkdir -p '.escapeshellarg(self::CONTAINER_BACKUP_ROOT),
            $exec.' sh -c '.escapeshellarg($backupScript).' sh '.escapeshellarg($workDir).' '.escapeshellarg($bucket),
            $exec.' tar -czf '.escapeshellarg($containerArchivePath).' -C '.escapeshellarg(self::CONTAINER_BACKUP_ROOT).' '.escapeshellarg(basename($workDir)),
            'docker cp '.escapeshellarg($containerName.':'.$containerArchivePath).' '.escapeshellarg($backupDirectory.'/'.$archiveName),
        ];
    }

    public static function cleanup(string $containerName, string $archiveName): string
    {
        validateFilenameSafe($archiveName, 'InfluxDB backup archive');

        return 'docker exec '.escapeshellarg($containerName).' rm -rf '
            .escapeshellarg(self::workDir($archiveName)).' '
            .escapeshellarg(self::containerArchivePath($archiveName));
    }

    private static function workDir(string $archiveName): string
    {
        return self::CONTAINER_BACKUP_ROOT.'/'.preg_replace('/\.tar\.gz$/', '', $archiveName);
    }

    private static function containerArchivePath(string $archiveName): string
    {
        return self::CONTAINER_BACKUP_ROOT.'/'.$archiveName;
    }
}
