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

    expect($result)->toContain('gunzip -cf /tmp/test.dump');
    expect($result)->toContain('psql -U ${POSTGRES_USER} -d ${POSTGRES_DB:-${POSTGRES_USER:-postgres}}');
});

test('buildRestoreCommand handles MySQL without dumpAll', function () {
    $component = importFormWithResource('App\Models\StandaloneMysql');
    $component->dumpAll = false;
    $component->mysqlRestoreCommand = 'mysql -u $MYSQL_USER -p$MYSQL_PASSWORD $MYSQL_DATABASE';

    $result = $component->buildRestoreCommand('/tmp/test.dump');

    expect($result)->toContain('mysql -u $MYSQL_USER');
    expect($result)->toContain('< /tmp/test.dump');
});

test('buildRestoreCommand handles MariaDB without dumpAll', function () {
    $component = importFormWithResource('App\Models\StandaloneMariadb');
    $component->dumpAll = false;
    $component->mariadbRestoreCommand = 'mariadb -u $MARIADB_USER -p$MARIADB_PASSWORD $MARIADB_DATABASE';

    $result = $component->buildRestoreCommand('/tmp/test.dump');

    expect($result)->toContain('mariadb -u $MARIADB_USER');
    expect($result)->toContain('< /tmp/test.dump');
});

test('buildRestoreCommand always appends the MongoDB archive path', function (bool $dumpAll) {
    $component = importFormWithResource('App\Models\StandaloneMongodb');
    $component->dumpAll = $dumpAll;
    $component->mongodbRestoreCommand = 'mongorestore --authenticationDatabase=admin --username $MONGO_INITDB_ROOT_USERNAME --password $MONGO_INITDB_ROOT_PASSWORD --uri mongodb://localhost:27017 --gzip --archive=';

    $result = $component->buildRestoreCommand('/tmp/test.dump');

    expect($result)->toContain('mongorestore');
    expect($result)->toContain('--archive=/tmp/test.dump');
})->with([false, true]);
