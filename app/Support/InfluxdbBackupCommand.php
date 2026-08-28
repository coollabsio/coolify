<?php

namespace App\Support;

final class InfluxdbBackupCommand
{
    private const CONTAINER_BACKUP_ROOT = '/tmp/coolify-influxdb-backup';

    /**
     * InfluxDB 2.x writes a backup as a directory of files, so the dump is tarred
     * inside the container before it is copied out as a single archive.
     *
     * @return array<int, string>
     */
    public static function make(
        string $containerName,
        string $bucket,
        string $archiveName,
        string $backupDirectory,
        string $adminToken,
    ): array {
        validateShellSafePath($bucket, 'bucket name');
        validateFilenameSafe($archiveName, 'InfluxDB backup archive');

        $backupDirectory = rtrim($backupDirectory, '/');
        $workDir = self::workDir($archiveName);
        $containerArchivePath = self::containerArchivePath($archiveName);
        $backupLocation = $backupDirectory.'/'.$archiveName;

        $exec = 'docker exec '.escapeshellarg($containerName);
        $execWithToken = 'docker exec -e INFLUX_TOKEN='.escapeshellarg($adminToken).' '.escapeshellarg($containerName);

        return [
            'mkdir -p '.escapeshellarg($backupDirectory),
            $exec.' rm -rf '.escapeshellarg($workDir).' '.escapeshellarg($containerArchivePath),
            $exec.' mkdir -p '.escapeshellarg(self::CONTAINER_BACKUP_ROOT),
            $execWithToken.' influx backup '.escapeshellarg($workDir).' --host http://127.0.0.1:8086 --bucket '.escapeshellarg($bucket),
            $exec.' tar -czf '.escapeshellarg($containerArchivePath).' -C '.escapeshellarg(self::CONTAINER_BACKUP_ROOT).' '.escapeshellarg(basename($workDir)),
            'docker cp '.escapeshellarg($containerName.':'.$containerArchivePath).' '.escapeshellarg($backupLocation),
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
        return self::CONTAINER_BACKUP_ROOT.'/'.self::baseName($archiveName);
    }

    private static function containerArchivePath(string $archiveName): string
    {
        return self::CONTAINER_BACKUP_ROOT.'/'.$archiveName;
    }

    private static function baseName(string $archiveName): string
    {
        return (string) preg_replace('/\.tar\.gz$/', '', $archiveName);
    }
}
