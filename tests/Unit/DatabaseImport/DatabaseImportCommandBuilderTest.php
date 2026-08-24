<?php

use App\Models\StandaloneRedis;
use App\Support\DatabaseImport\DatabaseImportCommandBuilder;
use AppModels\ServiceDatabase;
use AppModels\StandaloneMariadb;
use AppModels\StandaloneMongodb;
use AppModels\StandaloneMysql;
use AppModels\StandalonePostgresql;

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

test('builds dump-all commands and postgres safety scan', function () {
    $builder = new DatabaseImportCommandBuilder;
    $postgres = importResource(StandalonePostgresql::class);

    expect($builder->buildRestoreCommand($postgres, '/tmp/dump.sql.gz', true))
        ->toContain('pg_terminate_backend')
        ->toContain("gunzip -cf '/tmp/dump.sql.gz'")
        ->and($builder->buildPostgresSafetyCommand($postgres, 'postgres-safe', '/tmp/dump.sql.gz'))
        ->toContain('COPY ... PROGRAM')
        ->toContain('docker exec postgres-safe')
        ->toContain('| tr')
        ->toContain('/\\*[^*]*\\*/');
});

test('rejects unsupported database types', function () {
    $builder = new DatabaseImportCommandBuilder;
    $redis = importResource(StandaloneRedis::class);

    expect(fn () => $builder->buildRestoreCommand($redis, '/tmp/backup', false))
        ->toThrow(InvalidArgumentException::class, 'not supported');
});
