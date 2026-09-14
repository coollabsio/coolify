<?php

use App\Models\ServiceDatabase;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Support\DatabaseImport\DatabaseImportCommandBuilder;

function importResource(string $class, ?string $databaseType = null): object
{
    $resource = Mockery::mock($class);
    $resource->shouldReceive('getMorphClass')->andReturn($class);
    if ($class === ServiceDatabase::class) {
        $resource->shouldReceive('databaseType')->andReturn($databaseType);
    }

    return $resource;
}

test('builds database-specific restore commands', function (string $class, ?string $type, string $needle) {
    $builder = new DatabaseImportCommandBuilder;

    $command = $builder->buildRestoreCommand(importResource($class, $type), '/tmp/restore file', false);

    expect($command)->toContain($needle)->toContain("'/tmp/restore file'");
})->with([
    'postgresql' => [StandalonePostgresql::class, null, 'pg_restore'],
    'mysql' => [StandaloneMysql::class, null, 'mysql -u $MYSQL_USER'],
    'mariadb' => [StandaloneMariadb::class, null, 'mariadb -u $MARIADB_USER'],
    'mongodb' => [StandaloneMongodb::class, null, 'mongorestore'],
    'service postgres' => [ServiceDatabase::class, 'postgresql', 'pg_restore'],
    'service mysql' => [ServiceDatabase::class, 'mysql', 'mysql -u $MYSQL_USER'],
    'service mariadb' => [ServiceDatabase::class, 'mariadb', 'mariadb -u $MARIADB_USER'],
    'service mongo' => [ServiceDatabase::class, 'mongodb', 'mongorestore'],
]);

test('decompresses gzip backups for single-database mysql and mariadb restores', function (string $class, ?string $type, string $client) {
    $builder = new DatabaseImportCommandBuilder;

    $command = $builder->buildRestoreCommand(importResource($class, $type), '/tmp/restore file.sql.gz', false);

    expect($command)->toBe(
        "(gunzip -cf '/tmp/restore file.sql.gz' 2>/dev/null || cat '/tmp/restore file.sql.gz') | {$client}"
    );
})->with([
    'mysql' => [StandaloneMysql::class, null, 'mysql -u $MYSQL_USER -p$MYSQL_PASSWORD $MYSQL_DATABASE'],
    'mariadb' => [StandaloneMariadb::class, null, 'mariadb -u $MARIADB_USER -p$MARIADB_PASSWORD $MARIADB_DATABASE'],
    'service mysql' => [ServiceDatabase::class, 'mysql', 'mysql -u $MYSQL_USER -p$MYSQL_PASSWORD $MYSQL_DATABASE'],
    'service mariadb' => [ServiceDatabase::class, 'mariadb', 'mariadb -u $MARIADB_USER -p$MARIADB_PASSWORD $MARIADB_DATABASE'],
]);

test('builds dump-all commands and postgres safety scan', function () {
    $builder = new DatabaseImportCommandBuilder;
    $postgres = importResource(StandalonePostgresql::class);

    $safety = $builder->buildPostgresSafetyCommand($postgres, 'postgres-safe', '/tmp/dump.sql.gz');
    $script = $builder->buildPostgresRestoreScanScript($postgres, '/tmp/dump.sql.gz');

    expect($builder->buildRestoreCommand($postgres, '/tmp/dump.sql.gz', true))
        ->toContain('pg_terminate_backend')
        ->toContain("gunzip -cf '/tmp/dump.sql.gz'")
        ->and($safety)
        ->toContain('COPY ... PROGRAM')
        ->toContain('docker exec postgres-safe')
        ->toContain('pg_restore -l')
        ->toContain('pg_restore -f -')
        ->toContain('unable to inspect custom archive')
        ->not->toContain('then exit 0')
        ->and($script)
        ->toContain("tr '\\n\\r\\t'");
});

test('postgres safety command is null for non-postgres databases', function () {
    $builder = new DatabaseImportCommandBuilder;

    expect($builder->buildPostgresSafetyCommand(importResource(StandaloneMysql::class), 'mysql-test', '/tmp/restore_test'))
        ->toBeNull();
});

test('dump-all mysql and mariadb commands use valid shell parameter expansions', function (string $class, string $binary, string $prefix) {
    $builder = new DatabaseImportCommandBuilder;

    $command = $builder->buildRestoreCommand(importResource($class), '/tmp/dump.sql.gz', true);

    $rootPassword = '${'.$prefix.'_ROOT_PASSWORD}';
    $database = '${'.$prefix.'_DATABASE:-default}';

    expect($command)
        ->toContain($binary)
        ->toContain("gunzip -cf '/tmp/dump.sql.gz'")
        ->toContain('-p'.$rootPassword)
        ->toContain('CREATE DATABASE IF NOT EXISTS \`'.$database.'\`')
        ->and(substr_count($command, $rootPassword))->toBe(6)
        ->and(substr_count($command, $database))->toBe(2)
        ->and($command)->not->toContain('${{');
})->with([
    'mysql' => [StandaloneMysql::class, 'mysql', 'MYSQL'],
    'mariadb' => [StandaloneMariadb::class, 'mariadb', 'MARIADB'],
]);

test('stops PostgreSQL restores on the first error without replacing existing objects by default', function () {
    $builder = new DatabaseImportCommandBuilder;
    $postgres = importResource(StandalonePostgresql::class);

    expect($builder->buildRestoreCommand($postgres, '/tmp/backup.dump', false, false))
        ->toContain('--exit-on-error')
        ->not->toContain('--clean')
        ->not->toContain('--if-exists');
});

test('replaces existing PostgreSQL objects when requested', function () {
    $builder = new DatabaseImportCommandBuilder;
    $postgres = importResource(StandalonePostgresql::class);

    expect($builder->buildRestoreCommand($postgres, '/tmp/backup.dump', false, true))
        ->toContain('--clean')
        ->toContain('--if-exists')
        ->toContain('--exit-on-error');
});

test('rejects unsupported database types', function () {
    $builder = new DatabaseImportCommandBuilder;
    $redis = importResource(StandaloneRedis::class);

    expect(fn () => $builder->buildRestoreCommand($redis, '/tmp/backup', false))
        ->toThrow(InvalidArgumentException::class, 'not supported');
});
