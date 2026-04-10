<?php

use App\Livewire\Project\Database\Import;

test('buildRestoreCommand handles PostgreSQL without dumpAll', function () {
    $component = new Import;
    $component->dumpAll = false;
    $component->postgresqlRestoreCommand = 'pg_restore -U $POSTGRES_USER -d $POSTGRES_DB';

    $database = Mockery::mock('App\Models\StandalonePostgresql');
    $database->shouldReceive('getMorphClass')->andReturn('App\Models\StandalonePostgresql');
    $component->resource = $database;

    $result = $component->buildRestoreCommand('/tmp/test.dump');

    expect($result)->toContain('pg_restore');
    expect($result)->toContain('/tmp/test.dump');
});

test('buildRestoreCommand handles PostgreSQL with dumpAll', function () {
    $component = new Import;
    $component->dumpAll = true;
    // This is the full dump-all command prefix that would be set in the updatedDumpAll method
    $component->postgresqlRestoreCommand = 'psql -U $POSTGRES_USER -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname IS NOT NULL AND pid <> pg_backend_pid()" && psql -U $POSTGRES_USER -t -c "SELECT datname FROM pg_database WHERE NOT datistemplate" | xargs -I {} dropdb -U $POSTGRES_USER --if-exists {} && createdb -U $POSTGRES_USER postgres';

    $database = Mockery::mock('App\Models\StandalonePostgresql');
    $database->shouldReceive('getMorphClass')->andReturn('App\Models\StandalonePostgresql');
    $component->resource = $database;

    $result = $component->buildRestoreCommand('/tmp/test.dump');

    expect($result)->toContain('gunzip -cf /tmp/test.dump');
    expect($result)->toContain('psql -U $POSTGRES_USER postgres');
});

test('buildRestoreCommand handles MySQL without dumpAll', function () {
    $component = new Import;
    $component->dumpAll = false;
    $component->mysqlRestoreCommand = 'mysql -u $MYSQL_USER -p$MYSQL_PASSWORD $MYSQL_DATABASE';

    $database = Mockery::mock('App\Models\StandaloneMysql');
    $database->shouldReceive('getMorphClass')->andReturn('App\Models\StandaloneMysql');
    $component->resource = $database;

    $result = $component->buildRestoreCommand('/tmp/test.dump');

    expect($result)->toContain('mysql -u $MYSQL_USER');
    expect($result)->toContain('< /tmp/test.dump');
});

test('buildRestoreCommand handles MariaDB without dumpAll', function () {
    $component = new Import;
    $component->dumpAll = false;
    $component->mariadbRestoreCommand = 'mariadb -u $MARIADB_USER -p$MARIADB_PASSWORD $MARIADB_DATABASE';

    $database = Mockery::mock('App\Models\StandaloneMariadb');
    $database->shouldReceive('getMorphClass')->andReturn('App\Models\StandaloneMariadb');
    $component->resource = $database;

    $result = $component->buildRestoreCommand('/tmp/test.dump');

    expect($result)->toContain('mariadb -u $MARIADB_USER');
    expect($result)->toContain('< /tmp/test.dump');
});

test('buildRestoreCommand handles MongoDB', function () {
    $component = new Import;
    $component->dumpAll = false;
    $component->mongodbRestoreCommand = 'mongorestore --authenticationDatabase=admin --username $MONGO_INITDB_ROOT_USERNAME --password $MONGO_INITDB_ROOT_PASSWORD --uri mongodb://localhost:27017 --gzip --archive=';

    $database = Mockery::mock('App\Models\StandaloneMongodb');
    $database->shouldReceive('getMorphClass')->andReturn('App\Models\StandaloneMongodb');
    $component->resource = $database;

    $result = $component->buildRestoreCommand('/tmp/test.dump');

    expect($result)->toContain('mongorestore');
    expect($result)->toContain('/tmp/test.dump');
});
