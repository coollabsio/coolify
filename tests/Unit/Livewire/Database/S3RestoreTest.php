<?php

use App\Livewire\Project\Database\ImportForm;

function importFormWithResource(string $modelClass): ImportForm
{
    $component = new class extends ImportForm
    {
        public $resource;
    };

    $database = Mockery::mock($modelClass);
    $database->shouldReceive('getMorphClass')->andReturn($modelClass);
    $component->resource = $database;

    return $component;
}

/**
 * Runs the dump-all restore command with stub clients. Each entry is the
 * client name and the first five bytes it read from stdin.
 *
 * @return list<string>
 */
function invokedPostgresRestoreClients(string $contents, bool $gzip = false): array
{
    $binDir = sys_get_temp_dir().'/coolify-pg-restore-'.bin2hex(random_bytes(8));
    mkdir($binDir);

    try {
        $logFile = $binDir.'/invoked.log';
        file_put_contents($logFile, '');

        foreach (['pg_restore', 'psql', 'dropdb', 'createdb'] as $tool) {
            $stub = <<<SH
#!/bin/sh
header=''
if [ ! -t 0 ]; then
    header=\$(head -c 5)
    cat >/dev/null
fi
printf '%s:%s\\n' '{$tool}' "\$header" >> '{$logFile}'
exit 0
SH;
            $path = $binDir.'/'.$tool;
            file_put_contents($path, $stub);
            chmod($path, 0755);
        }

        $dumpPath = $binDir.'/backup.dump';
        $payload = $gzip ? gzencode($contents) : $contents;
        expect($payload)->not->toBeFalse();
        file_put_contents($dumpPath, $payload);

        $component = importFormWithResource('App\Models\StandalonePostgresql');
        $component->dumpAll = true;

        $process = proc_open(
            ['sh', '-c', $component->buildRestoreCommand($dumpPath)],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $binDir,
            [
                'PATH' => $binDir.PATH_SEPARATOR.(getenv('PATH') ?: '/usr/bin:/bin'),
                'POSTGRES_USER' => 'postgres',
                'POSTGRES_DB' => 'app',
            ]
        );

        expect($process)->not->toBeFalse();

        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        expect(proc_close($process))->toBe(0);

        return array_values(array_filter(explode("\n", trim((string) file_get_contents($logFile)))));
    } finally {
        foreach (glob($binDir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($binDir);
    }
}

test('buildRestoreCommand handles PostgreSQL without dumpAll', function () {
    $component = importFormWithResource('App\Models\StandalonePostgresql');
    $component->dumpAll = false;

    $result = $component->buildRestoreCommand('/tmp/test.dump');

    expect($result)->toContain('pg_restore --exit-on-error');
    expect($result)->toStartWith("backup='/tmp/test.dump'");
});

test('buildRestoreCommand handles PostgreSQL with dumpAll', function () {
    $component = importFormWithResource('App\Models\StandalonePostgresql');
    $component->dumpAll = true;

    $result = $component->buildRestoreCommand('/tmp/test.dump');

    expect($result)->toStartWith("backup='/tmp/test.dump'");
    expect($result)->toContain('stream | psql -U ${POSTGRES_USER} -d "$db"');
});

test('buildRestoreCommand dump-all PostgreSQL restore is pg_restore for PGDMP otherwise psql', function () {
    $component = importFormWithResource('App\Models\StandalonePostgresql');
    $component->dumpAll = true;

    $command = $component->buildRestoreCommand('/tmp/test.dump');

    expect($command)
        ->toContain('if [ "$(stream | head -c 5)" = PGDMP ] || is_tar; then')
        ->toContain('stream | pg_restore -U ${POSTGRES_USER} -d "$db"')
        ->toContain('stream | psql -U ${POSTGRES_USER} -d "$db"')
        ->and(strpos($command, 'kind=archive'))->toBeLessThan(strpos($command, 'dropdb'));
});

test('dump-all PostgreSQL import text shows the client chosen by the dump format', function () {
    $component = importFormWithResource('App\Models\StandalonePostgresql');
    $component->updatedDumpAll(true);

    expect($component->restoreCommandText)->toBe($component->buildRestoreCommand('<temp_backup_file>'));
});

test('dump-all PostgreSQL restore selects the client for the dump format', function (string $contents, bool $gzip, string $restore, array $preflight) {
    // Check the archive, terminate sessions, list databases, recreate the target database, then restore.
    expect(invokedPostgresRestoreClients($contents, $gzip))->toBe([...$preflight, 'psql:', 'psql:', 'createdb:', $restore]);
})->with([
    'custom archive' => ['PGDMP'.str_repeat("\0", 16), false, 'pg_restore:PGDMP', ['pg_restore:PGDMP']],
    'gzip custom archive' => ['PGDMP'.str_repeat("\0", 16), true, 'pg_restore:PGDMP', ['pg_restore:PGDMP']],
    'plain SQL' => ["-- PostgreSQL database dump\nSELECT 1;\n", false, 'psql:-- Po', []],
    'gzip SQL' => ["-- PostgreSQL database dump\nSELECT 1;\n", true, 'psql:-- Po', []],
]);

test('buildRestoreCommand handles MySQL without dumpAll', function () {
    $component = importFormWithResource('App\Models\StandaloneMysql');
    $component->dumpAll = false;

    $result = $component->buildRestoreCommand('/tmp/test.dump');

    expect($result)->toStartWith("backup='/tmp/test.dump'");
    expect($result)->toContain('stream | mysql -u $MYSQL_USER -p$MYSQL_PASSWORD $MYSQL_DATABASE');
    expect($result)->not->toContain("< '/tmp/test.dump'");
});

test('buildRestoreCommand handles MariaDB without dumpAll', function () {
    $component = importFormWithResource('App\Models\StandaloneMariadb');
    $component->dumpAll = false;

    $result = $component->buildRestoreCommand('/tmp/test.dump');

    expect($result)->toStartWith("backup='/tmp/test.dump'");
    expect($result)->toContain('stream | mariadb -u $MARIADB_USER -p$MARIADB_PASSWORD $MARIADB_DATABASE');
    expect($result)->not->toContain("< '/tmp/test.dump'");
});

test('buildRestoreCommand always appends the MongoDB archive path', function (bool $dumpAll) {
    $component = importFormWithResource('App\Models\StandaloneMongodb');
    $component->dumpAll = $dumpAll;

    $result = $component->buildRestoreCommand('/tmp/test.dump');

    expect($result)->toStartWith("backup='/tmp/test.dump'");
    expect($result)->toContain('mongorestore');
    expect($result)->toContain('restore --gzip --archive="$backup"');
})->with([false, true]);
