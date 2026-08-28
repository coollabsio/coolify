<?php

namespace App\Support;

final class InfluxdbRestoreCommand
{
    private const HOST = 'http://127.0.0.1:8086';

    /**
     * Build the restore script that runs inside the InfluxDB container.
     *
     * The archive produced by {@see InfluxdbBackupCommand} is a tarred dump
     * directory, so it is unpacked before `influx restore` is handed the
     * directory it expects. Credentials come from the container's own
     * DOCKER_INFLUXDB_INIT_* environment, matching how the other database
     * restores reference $POSTGRES_USER and friends.
     *
     * `influx restore --bucket` refuses to write into an existing bucket, so the
     * target bucket is dropped first. That is destructive by design — the import
     * screen warns about it — but it stays scoped to the bucket and never
     * touches the instance's tokens or users the way `--full` would.
     */
    public static function script(string $archivePath): string
    {
        $escapedArchive = escapeshellarg($archivePath);
        $host = self::HOST;

        return <<<SH
        set -e
        RESTORE_DIR="\$(mktemp -d)"
        trap 'rm -rf "\$RESTORE_DIR"' EXIT
        tar -xzf {$escapedArchive} -C "\$RESTORE_DIR"
        SRC="\$(find "\$RESTORE_DIR" -mindepth 1 -maxdepth 1 -type d | head -n 1)"
        if [ -z "\$SRC" ]; then
          SRC="\$RESTORE_DIR"
        fi
        influx bucket delete --name "\$DOCKER_INFLUXDB_INIT_BUCKET" --org "\$DOCKER_INFLUXDB_INIT_ORG" --host {$host} --token "\$DOCKER_INFLUXDB_INIT_ADMIN_TOKEN" >/dev/null 2>&1 || true
        influx restore "\$SRC" --bucket "\$DOCKER_INFLUXDB_INIT_BUCKET" --host {$host} --token "\$DOCKER_INFLUXDB_INIT_ADMIN_TOKEN"
        SH;
    }

    /** Human-readable summary shown on the import screen. */
    public static function preview(): string
    {
        return self::script('<temp_backup_file>');
    }
}
