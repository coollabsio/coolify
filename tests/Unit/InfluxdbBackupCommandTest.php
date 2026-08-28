<?php

use App\Models\StandaloneInfluxdb;
use App\Support\InfluxdbBackupCommand;

test('influxdb exposes scheduled backups', function () {
    expect((new StandaloneInfluxdb)->isBackupSolutionAvailable())->toBeTrue();
});

test('builds a native influxdb backup command that tars the dump before copying it out', function () {
    $commands = InfluxdbBackupCommand::make(
        containerName: 'influxdb-uuid',
        bucket: 'metrics',
        archiveName: 'influxdb-backup-123-uuid.tar.gz',
        backupDirectory: '/data/coolify/backups/influxdb',
        adminToken: 'secret-token',
    );

    expect($commands)->toHaveCount(6)
        ->and($commands[0])->toBe("mkdir -p '/data/coolify/backups/influxdb'")
        ->and($commands[3])->toContain("docker exec -e INFLUX_TOKEN='secret-token' 'influxdb-uuid' influx backup")
        ->and($commands[3])->toContain("--bucket 'metrics'")
        ->and($commands[3])->toContain('--host http://127.0.0.1:8086')
        ->and($commands[5])->toBe("docker cp 'influxdb-uuid:/tmp/coolify-influxdb-backup/influxdb-backup-123-uuid.tar.gz' '/data/coolify/backups/influxdb/influxdb-backup-123-uuid.tar.gz'");
});

test('influxdb backup work directory drops the archive extension so the tar is not nested', function () {
    $commands = InfluxdbBackupCommand::make(
        containerName: 'influxdb-uuid',
        bucket: 'metrics',
        archiveName: 'influxdb-backup-123-uuid.tar.gz',
        backupDirectory: '/data/coolify/backups/influxdb',
        adminToken: 'secret-token',
    );

    expect($commands[3])->toContain("'/tmp/coolify-influxdb-backup/influxdb-backup-123-uuid'")
        ->and($commands[3])->not->toContain("influxdb-backup-123-uuid.tar.gz'")
        ->and($commands[4])->toContain("tar -czf '/tmp/coolify-influxdb-backup/influxdb-backup-123-uuid.tar.gz'")
        ->and($commands[4])->toContain("-C '/tmp/coolify-influxdb-backup' 'influxdb-backup-123-uuid'");
});

test('influxdb cleanup removes both the dump directory and the archive inside the container', function () {
    expect(InfluxdbBackupCommand::cleanup('influxdb-uuid', 'influxdb-backup-123-uuid.tar.gz'))
        ->toBe("docker exec 'influxdb-uuid' rm -rf '/tmp/coolify-influxdb-backup/influxdb-backup-123-uuid' '/tmp/coolify-influxdb-backup/influxdb-backup-123-uuid.tar.gz'");
});

test('the admin token is shell-escaped and never interpolated raw', function () {
    $commands = InfluxdbBackupCommand::make(
        containerName: 'influxdb-uuid',
        bucket: 'metrics',
        archiveName: 'influxdb-backup-123-uuid.tar.gz',
        backupDirectory: '/data/coolify/backups/influxdb',
        adminToken: "tok'; rm -rf /; echo '",
    );

    // The payload survives verbatim, but only inside one escapeshellarg-quoted
    // argument, so no shell ever sees it as a separate command.
    expect($commands[3])->toContain('INFLUX_TOKEN='.escapeshellarg("tok'; rm -rf /; echo '"))
        ->and($commands[3])->not->toContain("INFLUX_TOKEN=tok'; rm -rf /");
});

test('rejects unsafe influxdb bucket names', function () {
    expect(fn () => InfluxdbBackupCommand::make(
        containerName: 'influxdb-uuid',
        bucket: 'metrics`; rm -rf /; echo `',
        archiveName: 'influxdb-backup-123-uuid.tar.gz',
        backupDirectory: '/data/coolify/backups/influxdb',
        adminToken: 'secret-token',
    ))->toThrow(Exception::class);
});

test('rejects archive names that escape the backup directory', function () {
    expect(fn () => InfluxdbBackupCommand::make(
        containerName: 'influxdb-uuid',
        bucket: 'metrics',
        archiveName: '../../etc/passwd',
        backupDirectory: '/data/coolify/backups/influxdb',
        adminToken: 'secret-token',
    ))->toThrow(Exception::class);
});
