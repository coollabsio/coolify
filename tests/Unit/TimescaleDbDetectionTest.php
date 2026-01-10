<?php

use App\Models\ServiceDatabase;

use function PHPUnit\Framework\assertTrue;

test('timescaledb is detected as database with postgres environment variables', function () {
    $image = 'timescale/timescaledb';
    $serviceConfig = [
        'image' => 'timescale/timescaledb',
        'environment' => [
            'POSTGRES_DB=$POSTGRES_DB',
            'POSTGRES_USER=$SERVICE_USER_POSTGRES',
            'POSTGRES_PASSWORD=$SERVICE_PASSWORD_POSTGRES',
        ],
        'volumes' => [
            'timescaledb-data:/var/lib/postgresql/data',
        ],
    ];

    $isDatabase = isDatabaseImage($image, $serviceConfig);

    assertTrue($isDatabase, 'TimescaleDB with POSTGRES_PASSWORD should be detected as database');
});

test('timescaledb is detected as database without service config', function () {
    $image = 'timescale/timescaledb';

    $isDatabase = isDatabaseImage($image);

    assertTrue($isDatabase, 'TimescaleDB image should be in DATABASE_DOCKER_IMAGES constant');
});

test('timescaledb-ha is detected as database', function () {
    $image = 'timescale/timescaledb-ha';

    $isDatabase = isDatabaseImage($image);

    assertTrue($isDatabase, 'TimescaleDB HA image should be in DATABASE_DOCKER_IMAGES constant');
});

test('timescaledb databaseType returns postgresql', function () {
    $database = new ServiceDatabase;
    $database->setRawAttributes(['image' => 'timescale/timescaledb:latest', 'custom_type' => null]);
    $database->syncOriginal();

    $type = $database->databaseType();

    expect($type)->toBe('standalone-postgresql');
});

test('timescaledb-ha databaseType returns postgresql', function () {
    $database = new ServiceDatabase;
    $database->setRawAttributes(['image' => 'timescale/timescaledb-ha:pg17', 'custom_type' => null]);
    $database->syncOriginal();

    $type = $database->databaseType();

    expect($type)->toBe('standalone-postgresql');
});

test('timescaledb backup solution is available', function () {
    $database = new ServiceDatabase;
    $database->setRawAttributes(['image' => 'timescale/timescaledb:latest', 'custom_type' => null]);
    $database->syncOriginal();

    $isAvailable = $database->isBackupSolutionAvailable();

    assertTrue($isAvailable, 'TimescaleDB should have backup solution available');
});

test('timescaledb-ha backup solution is available', function () {
    $database = new ServiceDatabase;
    $database->setRawAttributes(['image' => 'timescale/timescaledb-ha:pg17', 'custom_type' => null]);
    $database->syncOriginal();

    $isAvailable = $database->isBackupSolutionAvailable();

    assertTrue($isAvailable, 'TimescaleDB HA should have backup solution available');
});
