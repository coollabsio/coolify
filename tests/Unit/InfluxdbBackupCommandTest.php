<?php

use App\Models\Server;
use App\Models\StandaloneInfluxdb;
use App\Support\InfluxdbBackupCommand;
use Illuminate\Filesystem\Filesystem;

function influxdbBackupCommands(string $bucket = 'metrics', string $archiveName = 'influxdb-backup-1-uuid.tar.gz'): array
{
    return InfluxdbBackupCommand::make(
        containerName: 'influx-uuid',
        bucket: $bucket,
        archiveName: $archiveName,
        backupDirectory: '/data/coolify/backups/influx/',
    );
}

test('influxdb exposes scheduled backups', function () {
    expect((new StandaloneInfluxdb)->isBackupSolutionAvailable())->toBeTrue();
});

test('builds an influx backup that is packed as one archive and copied out of the container', function () {
    expect(influxdbBackupCommands())->toBe([
        "mkdir -p '/data/coolify/backups/influx'",
        "docker exec 'influx-uuid' rm -rf '/tmp/coolify-influxdb-backup/influxdb-backup-1-uuid' '/tmp/coolify-influxdb-backup/influxdb-backup-1-uuid.tar.gz'",
        "docker exec 'influx-uuid' mkdir -p '/tmp/coolify-influxdb-backup'",
        "docker exec 'influx-uuid' sh -c 'influx backup \"\$1\" --host http://127.0.0.1:8086 --bucket \"\$2\" --token \"\$DOCKER_INFLUXDB_INIT_ADMIN_TOKEN\"' sh '/tmp/coolify-influxdb-backup/influxdb-backup-1-uuid' 'metrics'",
        "docker exec 'influx-uuid' tar -czf '/tmp/coolify-influxdb-backup/influxdb-backup-1-uuid.tar.gz' -C '/tmp/coolify-influxdb-backup' 'influxdb-backup-1-uuid'",
        "docker cp 'influx-uuid:/tmp/coolify-influxdb-backup/influxdb-backup-1-uuid.tar.gz' '/data/coolify/backups/influx/influxdb-backup-1-uuid.tar.gz'",
    ])->and(InfluxdbBackupCommand::cleanup('influx-uuid', 'influxdb-backup-1-uuid.tar.gz'))
        ->toBe("docker exec 'influx-uuid' rm -rf '/tmp/coolify-influxdb-backup/influxdb-backup-1-uuid' '/tmp/coolify-influxdb-backup/influxdb-backup-1-uuid.tar.gz'");
});

test('the backup reads the admin token inside the container, not from the command line', function (bool $nonRootUser) {
    $dir = sys_get_temp_dir().'/coolify-influx-backup-'.bin2hex(random_bytes(8));
    mkdir($dir);

    try {
        file_put_contents($dir.'/docker', "#!/bin/sh\nshift 2\nexec \"\$@\"\n");
        file_put_contents($dir.'/sudo', "#!/bin/sh\nexec \"\$@\"\n");
        chmod($dir.'/sudo', 0755);
        file_put_contents($dir.'/influx', "#!/bin/sh\nprintf '%s\\n' \"\$*\" > ".escapeshellarg($dir.'/influx.log')."\n");
        chmod($dir.'/docker', 0755);
        chmod($dir.'/influx', 0755);

        $command = influxdbBackupCommands()[3];
        if ($nonRootUser) {
            $command = parseCommandsByLineForSudo(collect([$command]), new Server(['user' => 'ubuntu']))[0];
            expect($command)->toStartWith('sudo ');
        }
        exec('env PATH='.escapeshellarg($dir.':'.getenv('PATH')).' DOCKER_INFLUXDB_INIT_ADMIN_TOKEN=container-token sh -c '.escapeshellarg($command), $output, $exitCode);

        expect($exitCode)->toBe(0)
            ->and(trim((string) file_get_contents($dir.'/influx.log')))
            ->toBe('backup /tmp/coolify-influxdb-backup/influxdb-backup-1-uuid --host http://127.0.0.1:8086 --bucket metrics --token container-token');
    } finally {
        (new Filesystem)->deleteDirectory($dir);
    }
})->with(['root user' => false, 'non-root user with sudo' => true]);

test('rejects unsafe influxdb bucket names', function (string $bucket) {
    expect(fn () => influxdbBackupCommands(bucket: $bucket))->toThrow(Exception::class);
})->with([
    'command substitution' => ['metrics$(id)'],
    'command separator' => ['metrics; rm -rf /'],
    'backtick' => ['metrics`id`'],
]);

test('rejects archive names that leave the backup directory', function (string $archiveName) {
    expect(fn () => influxdbBackupCommands(archiveName: $archiveName))->toThrow(Exception::class)
        ->and(fn () => InfluxdbBackupCommand::cleanup('influx-uuid', $archiveName))->toThrow(Exception::class);
})->with([
    'parent directory' => ['../backup.tar.gz'],
    'absolute path' => ['/etc/passwd'],
]);
