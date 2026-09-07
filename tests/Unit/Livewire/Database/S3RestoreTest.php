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
 * @return list<string>
 */
function invokedPostgresRestoreClients(string $contents, bool $gzip = false): array
{
    $binDir = sys_get_temp_dir().'/coolify-pg-restore-'.bin2hex(random_bytes(8));
    mkdir($binDir);

    try {
        $logFile = $binDir.'/invoked.log';
        file_put_contents($logFile, '');

        foreach (['pg_restore', 'psql'] as $tool) {
            $stub = <<<SH
#!/bin/sh
printf '%s\\n' '{$tool}' >> '{$logFile}'
if [ ! -t 0 ]; then
    cat >/dev/null
fi
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
        $component->postgresqlRestoreCommand = ':';

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
    $component->postgresqlRestoreCommand = 'pg_restore -U $POSTGRES_USER -d $POSTGRES_DB';

    $result = $component->buildRestoreCommand('/tmp/test.dump');

    expect($result)->toContain('pg_restore');
    expect($result)->toContain('/tmp/test.dump');
});

test('buildRestoreCommand handles PostgreSQL with dumpAll', function () {
    $component = importFormWithResource('App\Models\StandalonePostgresql');
    $component->dumpAll = true;
    $component->postgresqlRestoreCommand = 'psql -U $POSTGRES_USER -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname IS NOT NULL AND pid <> pg_backend_pid()" && psql -U $POSTGRES_USER -t -c "SELECT datname FROM pg_database WHERE NOT datistemplate" | xargs -I {} dropdb -U $POSTGRES_USER --if-exists {} && createdb -U $POSTGRES_USER postgres';

    $result = $component->buildRestoreCommand('/tmp/test.dump');

    expect($result)->toContain("gunzip -cf '/tmp/test.dump'");
    expect($result)->toContain('psql -U ${POSTGRES_USER} -d ${POSTGRES_DB:-${POSTGRES_USER:-postgres}}');
});

test('buildRestoreCommand dump-all PostgreSQL restore is pg_restore for PGDMP otherwise psql', function () {
    $component = importFormWithResource('App\Models\StandalonePostgresql');
    $component->dumpAll = true;
    $component->postgresqlRestoreCommand = 'psql -U ${POSTGRES_USER} -c "cleanup"';

    $escapedTmpPath = escapeshellarg('/tmp/test.dump');

    expect($component->buildRestoreCommand('/tmp/test.dump'))->toBe(
        'psql -U ${POSTGRES_USER} -c "cleanup" && if [ "$({ gunzip -cf '.$escapedTmpPath.' 2>/dev/null || cat '.$escapedTmpPath.'; } | head -c 5)" = \'PGDMP\' ]; then pg_restore -U ${POSTGRES_USER} -d ${POSTGRES_DB:-${POSTGRES_USER:-postgres}} '.$escapedTmpPath.'; else (gunzip -cf '.$escapedTmpPath.' 2>/dev/null || cat '.$escapedTmpPath.') | psql -U ${POSTGRES_USER} -d ${POSTGRES_DB:-${POSTGRES_USER:-postgres}}; fi'
    );
});

test('dump-all PostgreSQL restore selects the client for the dump format', function (string $contents, bool $gzip, string $client) {
    expect(invokedPostgresRestoreClients($contents, $gzip))->toBe([$client]);
})->with([
    'custom archive' => ['PGDMP'.str_repeat("\0", 16), false, 'pg_restore'],
    'gzip custom archive' => ['PGDMP'.str_repeat("\0", 16), true, 'pg_restore'],
    'plain SQL' => ["-- PostgreSQL database dump\nSELECT 1;\n", false, 'psql'],
    'gzip SQL' => ["-- PostgreSQL database dump\nSELECT 1;\n", true, 'psql'],
]);

test('buildRestoreCommand handles MySQL without dumpAll', function () {
    $component = importFormWithResource('App\Models\StandaloneMysql');
    $component->dumpAll = false;
    $component->mysqlRestoreCommand = 'mysql -u $MYSQL_USER -p$MYSQL_PASSWORD $MYSQL_DATABASE';

    $result = $component->buildRestoreCommand('/tmp/test.dump');

    expect($result)->toContain('mysql -u $MYSQL_USER');
    expect($result)->toContain("< '/tmp/test.dump'");
});

test('buildRestoreCommand handles MariaDB without dumpAll', function () {
    $component = importFormWithResource('App\Models\StandaloneMariadb');
    $component->dumpAll = false;
    $component->mariadbRestoreCommand = 'mariadb -u $MARIADB_USER -p$MARIADB_PASSWORD $MARIADB_DATABASE';

    $result = $component->buildRestoreCommand('/tmp/test.dump');

    expect($result)->toContain('mariadb -u $MARIADB_USER');
    expect($result)->toContain("< '/tmp/test.dump'");
});

test('buildRestoreCommand always appends the MongoDB archive path', function (bool $dumpAll) {
    $component = importFormWithResource('App\Models\StandaloneMongodb');
    $component->dumpAll = $dumpAll;
    $component->mongodbRestoreCommand = 'mongorestore --authenticationDatabase=admin --username $MONGO_INITDB_ROOT_USERNAME --password $MONGO_INITDB_ROOT_PASSWORD --uri mongodb://localhost:27017 --gzip --archive=';

    $result = $component->buildRestoreCommand('/tmp/test.dump');

    expect($result)->toContain('mongorestore');
    expect($result)->toContain("--archive='/tmp/test.dump'");
})->with([false, true]);
