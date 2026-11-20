<?php

use App\Models\StandaloneClickhouse;
use App\Support\ClickhouseBackupCommand;

test('clickhouse exposes scheduled backups', function () {
    expect((new StandaloneClickhouse)->isBackupSolutionAvailable())->toBeTrue();
});

test('builds a native clickhouse backup command using container credentials', function () {
    $commands = ClickhouseBackupCommand::make(
        containerName: 'clickhouse-uuid',
        database: 'analytics',
        archiveName: 'backup-uuid.zip',
        backupDirectory: '/data/coolify/backups/clickhouse',
    );

    expect($commands)->toHaveCount(3)
        ->and($commands[0])->toBe("mkdir -p '/data/coolify/backups/clickhouse'")
        ->and($commands[1])->toContain("docker exec 'clickhouse-uuid' clickhouse-client")
        ->and($commands[1])->not->toContain('--password')
        ->and($commands[1])->toContain("BACKUP DATABASE `analytics` TO File('")
        ->and($commands[1])->toContain('backup-uuid.zip')
        ->and($commands[2])->toBe("docker cp 'clickhouse-uuid:/var/lib/clickhouse/backups/backup-uuid.zip' '/data/coolify/backups/clickhouse/backup-uuid.zip'")
        ->and(ClickhouseBackupCommand::cleanup('clickhouse-uuid', 'backup-uuid.zip'))
        ->toBe("docker exec 'clickhouse-uuid' rm -f '/var/lib/clickhouse/backups/backup-uuid.zip'");
});

test('rejects unsafe clickhouse database names', function () {
    expect(fn () => ClickhouseBackupCommand::make(
        containerName: 'clickhouse-uuid',
        database: 'analytics`; DROP DATABASE default; --',
        archiveName: 'backup-uuid.zip',
        backupDirectory: '/data/coolify/backups/clickhouse',
    ))->toThrow(Exception::class);
});
