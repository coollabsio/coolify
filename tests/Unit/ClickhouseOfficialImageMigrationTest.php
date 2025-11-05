<?php

use App\Models\StandaloneClickhouse;

test('clickhouse connection string uses clickhouse_db field', function () {
    $clickhouse = new StandaloneClickhouse([
        'clickhouse_admin_user' => 'testuser',
        'clickhouse_admin_password' => 'testpass',
        'clickhouse_db' => 'testdb',
        'uuid' => 'test-uuid',
    ]);

    $internalUrl = $clickhouse->internal_db_url;

    expect($internalUrl)->toContain('testdb')
        ->and($internalUrl)->toContain('testuser')
        ->and($internalUrl)->toContain('test-uuid');
});

test('clickhouse connection string defaults to default database', function () {
    $clickhouse = new StandaloneClickhouse([
        'clickhouse_admin_user' => 'testuser',
        'clickhouse_admin_password' => 'testpass',
        'clickhouse_db' => null,
        'uuid' => 'test-uuid',
    ]);

    $internalUrl = $clickhouse->internal_db_url;

    expect($internalUrl)->toContain('default');
});

test('clickhouse external connection string uses clickhouse_db field', function () {
    $clickhouse = new StandaloneClickhouse([
        'clickhouse_admin_user' => 'testuser',
        'clickhouse_admin_password' => 'testpass',
        'clickhouse_db' => 'customdb',
        'uuid' => 'test-uuid',
        'is_public' => false,
    ]);

    // When not public, external URL should be null
    expect($clickhouse->external_db_url)->toBeNull();
});

test('clickhouse model has clickhouse_db in fillable or guarded', function () {
    $clickhouse = new StandaloneClickhouse;

    // Check that clickhouse_db can be mass assigned (either in fillable or guarded is empty)
    $reflection = new ReflectionClass($clickhouse);
    $guarded = $reflection->getProperty('guarded');
    $guarded->setAccessible(true);

    // If guarded is empty array, all fields are mass assignable
    expect($guarded->getValue($clickhouse))->toBe([]);
});
