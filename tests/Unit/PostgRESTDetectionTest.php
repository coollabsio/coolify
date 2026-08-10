<?php

test('postgrest image is detected as application not database', function () {
    $result = isDatabaseImage('postgrest/postgrest:latest');
    expect($result)->toBeFalse();
});

test('postgrest image with version is detected as application', function () {
    $result = isDatabaseImage('postgrest/postgrest:v12.0.2');
    expect($result)->toBeFalse();
});

test('postgrest with registry prefix is detected as application', function () {
    $result = isDatabaseImage('ghcr.io/postgrest/postgrest:latest');
    expect($result)->toBeFalse();
});

test('regular postgres image is still detected as database', function () {
    $result = isDatabaseImage('postgres:15');
    expect($result)->toBeTrue();
});

test('postgres with registry prefix is detected as database', function () {
    $result = isDatabaseImage('docker.io/library/postgres:15');
    expect($result)->toBeTrue();
});

test('postgres image with service config is detected correctly', function () {
    $serviceConfig = [
        'image' => 'postgres:15',
        'environment' => [
            'POSTGRES_PASSWORD=secret',
        ],
    ];

    $result = isDatabaseImage('postgres:15', $serviceConfig);
    expect($result)->toBeTrue();
});

test('postgrest without service config is still detected as application', function () {
    $result = isDatabaseImage('postgrest/postgrest', null);
    expect($result)->toBeFalse();
});

test('supabase postgres-meta is detected as application', function () {
    $result = isDatabaseImage('supabase/postgres-meta:latest');
    expect($result)->toBeFalse();
});

test('mysql image is detected as database', function () {
    $result = isDatabaseImage('mysql:8.0');
    expect($result)->toBeTrue();
});

test('redis image is detected as database', function () {
    $result = isDatabaseImage('redis:7');
    expect($result)->toBeTrue();
});

test('timescale timescaledb is detected as database', function () {
    $result = isDatabaseImage('timescale/timescaledb:latest');
    expect($result)->toBeTrue();
});

test('mariadb is detected as database', function () {
    $result = isDatabaseImage('mariadb:10.11');
    expect($result)->toBeTrue();
});

test('mongodb is detected as database', function () {
    $result = isDatabaseImage('mongo:7');
    expect($result)->toBeTrue();
});
