<?php

/*
 * Verifies the opt-in read/write replica split in config/database.php.
 * The config file is re-required under different putenv() states so the
 * env() calls re-evaluate, then the resulting pgsql array shape is asserted.
 */

function loadDbConfig(): array
{
    return require base_path('config/database.php');
}

afterEach(function () {
    foreach ([
        'DB_READ_HOST', 'DB_READ_PORT', 'DB_READ_USERNAME', 'DB_READ_PASSWORD',
        'DB_WRITE_HOST', 'DB_WRITE_PORT', 'DB_WRITE_USERNAME', 'DB_WRITE_PASSWORD',
        'DB_STICKY',
    ] as $key) {
        putenv($key);
    }
});

it('has no replica keys when DB_READ_HOST is unset', function () {
    $pgsql = loadDbConfig()['connections']['pgsql'];

    expect($pgsql)
        ->not->toHaveKey('read')
        ->not->toHaveKey('write')
        ->not->toHaveKey('sticky')
        ->and($pgsql['driver'])->toBe('pgsql');
});

it('enables the read/write split when DB_READ_HOST is set', function () {
    putenv('DB_READ_HOST=replica1, replica2');

    $pgsql = loadDbConfig()['connections']['pgsql'];

    expect($pgsql)
        ->toHaveKey('read')
        ->toHaveKey('write')
        ->and($pgsql['read']['host'])->toBe(['replica1', 'replica2'])
        ->and($pgsql['sticky'])->toBeTrue();
});

it('falls back to DB_* values for unset replica options', function () {
    putenv('DB_READ_HOST=replica1');

    $pgsql = loadDbConfig()['connections']['pgsql'];

    expect($pgsql['read']['port'])->toBe(env('DB_PORT', '5432'))
        ->and($pgsql['read']['username'])->toBe(env('DB_USERNAME', 'coolify'))
        ->and($pgsql['write']['host'])->toBe([env('DB_HOST', 'coolify-db')]);
});

it('respects discrete replica overrides', function () {
    putenv('DB_READ_HOST=replica1');
    putenv('DB_READ_PORT=6432');
    putenv('DB_READ_USERNAME=reader');

    $pgsql = loadDbConfig()['connections']['pgsql'];

    expect($pgsql['read']['port'])->toBe('6432')
        ->and($pgsql['read']['username'])->toBe('reader');
});

it('disables sticky reads when DB_STICKY is false', function () {
    putenv('DB_READ_HOST=replica1');
    putenv('DB_STICKY=false');

    $pgsql = loadDbConfig()['connections']['pgsql'];

    expect($pgsql['sticky'])->toBeFalse();
});
