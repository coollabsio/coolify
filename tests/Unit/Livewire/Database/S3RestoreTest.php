<?php

use App\Livewire\Project\Database\Import;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;

class TestImportComponent extends Import
{
    public mixed $resource;
}

function importComponentFor(object $database): TestImportComponent
{
    $component = new TestImportComponent;
    $component->resource = $database;

    return $component;
}

test('buildRestoreCommand handles PostgreSQL dump all by default', function () {
    $database = Mockery::mock(StandalonePostgresql::class);
    $database->shouldReceive('getMorphClass')->andReturn(StandalonePostgresql::class);

    $component = importComponentFor($database);
    $component->updatedRestoreMode('dump_all');

    $result = $component->buildRestoreCommand('/tmp/test.dump');

    expect($result)->toContain('gunzip -cf /tmp/test.dump');
    expect($result)->toContain('{ gunzip -cf /tmp/test.dump 2>/dev/null || cat /tmp/test.dump; }');
    expect($result)->toContain('psql -X -U ${POSTGRES_USER} -d postgres');
});

test('buildRestoreCommand handles PostgreSQL legacy single db restore', function () {
    $database = Mockery::mock(StandalonePostgresql::class);
    $database->shouldReceive('getMorphClass')->andReturn(StandalonePostgresql::class);

    $component = importComponentFor($database);
    $component->updatedRestoreMode('legacy');
    $component->databasesToRestore = 'legacy_db';

    $result = $component->buildRestoreCommand('/tmp/test.dump');

    expect($result)->toContain('pg_restore');
    expect($result)->toContain('--clean --if-exists');
    expect($result)->toContain('-d legacy_db');
    expect($result)->toContain('/tmp/test.dump');
});

test('buildRestoreCommand handles PostgreSQL archive import restore', function () {
    $database = Mockery::mock(StandalonePostgresql::class);
    $database->shouldReceive('getMorphClass')->andReturn(StandalonePostgresql::class);

    $component = importComponentFor($database);
    $component->updatedRestoreMode('archive');
    $component->databasesToRestore = 'app_one,app_two';

    $result = $component->buildRestoreCommand('/tmp/test.tar.gz');

    expect($result)->toContain('tar -xzf /tmp/test.tar.gz -C /tmp/coolify-restore');
    expect($result)->toContain('pg_restore --clean --if-exists');
    expect($result)->toContain('pg-dump-app_one.dmp');
    expect($result)->toContain('pg-dump-app_two.dmp');
    expect($result)->toContain('pg_restore --clean --if-exists -U ${POSTGRES_USER} -d');
    expect($result)->toContain('rm -rf /tmp/coolify-restore');
});

test('buildRestoreCommand handles MySQL legacy single db restore', function () {
    $database = Mockery::mock(StandaloneMysql::class);
    $database->shouldReceive('getMorphClass')->andReturn(StandaloneMysql::class);

    $component = importComponentFor($database);
    $component->updatedRestoreMode('legacy');
    $component->databasesToRestore = 'legacy_db';

    $result = $component->buildRestoreCommand('/tmp/test.sql');

    expect($result)->toContain('mysql -u $MYSQL_USER');
    expect($result)->toContain('legacy_db < /tmp/test.sql');
    expect($result)->toContain('< /tmp/test.sql');
});

test('buildRestoreCommand handles MariaDB legacy single db restore', function () {
    $database = Mockery::mock(StandaloneMariadb::class);
    $database->shouldReceive('getMorphClass')->andReturn(StandaloneMariadb::class);

    $component = importComponentFor($database);
    $component->updatedRestoreMode('legacy');
    $component->databasesToRestore = 'legacy_db';

    $result = $component->buildRestoreCommand('/tmp/test.sql');

    expect($result)->toContain('mariadb -u $MARIADB_USER');
    expect($result)->toContain('legacy_db < /tmp/test.sql');
    expect($result)->toContain('< /tmp/test.sql');
});

test('buildRestoreCommand requires exactly one database for PostgreSQL legacy restore', function () {
    $database = Mockery::mock(StandalonePostgresql::class);
    $database->shouldReceive('getMorphClass')->andReturn(StandalonePostgresql::class);

    $component = importComponentFor($database);
    $component->updatedRestoreMode('legacy');
    $component->databasesToRestore = 'db_one,db_two';

    expect(fn () => $component->buildRestoreCommand('/tmp/test.dump'))
        ->toThrow(Exception::class, 'Please specify exactly one database to restore for a legacy backup file.');
});

test('buildRestoreCommand handles MongoDB', function () {
    $database = Mockery::mock(StandaloneMongodb::class);
    $database->shouldReceive('getMorphClass')->andReturn(StandaloneMongodb::class);

    $component = importComponentFor($database);

    $result = $component->buildRestoreCommand('/tmp/test.dump');

    expect($result)->toContain('mongorestore');
    expect($result)->toContain('--uri="mongodb://$MONGO_INITDB_ROOT_USERNAME:$MONGO_INITDB_ROOT_PASSWORD@localhost:27017/admin"');
    expect($result)->toContain('/tmp/test.dump');
});
