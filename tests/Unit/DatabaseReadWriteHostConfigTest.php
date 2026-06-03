<?php

use Illuminate\Support\Env;

function databaseConfigWithEnvironment(array $overrides): array
{
    $keys = [
        'DB_HOST',
        'DB_READ_HOST',
        'DB_WRITE_HOST',
    ];

    $repository = Env::getRepository();
    $original = [];

    foreach ($keys as $key) {
        $original[$key] = env($key);
        $repository->clear($key);
    }

    try {
        foreach ($overrides as $key => $value) {
            $repository->set($key, (string) $value);
        }

        return require __DIR__.'/../../config/database.php';
    } finally {
        foreach ($keys as $key) {
            $repository->clear($key);

            if ($original[$key] !== null) {
                $repository->set($key, (string) $original[$key]);
            }
        }
    }
}

it('trims and filters read hosts from comma separated values', function () {
    $config = databaseConfigWithEnvironment([
        'DB_READ_HOST' => ' read-1, read-2, ',
    ]);

    expect($config['connections']['pgsql']['read']['host'])->toBe(['read-1', 'read-2']);
});

it('falls back to db host when write host is empty', function () {
    $config = databaseConfigWithEnvironment([
        'DB_HOST' => 'primary-db',
        'DB_READ_HOST' => 'read-db',
        'DB_WRITE_HOST' => '',
    ]);

    expect($config['connections']['pgsql']['write']['host'])->toBe(['primary-db']);
});

it('falls back to the default host when write host and db host are empty', function () {
    $config = databaseConfigWithEnvironment([
        'DB_HOST' => '',
        'DB_READ_HOST' => 'read-db',
        'DB_WRITE_HOST' => '',
    ]);

    expect($config['connections']['pgsql']['write']['host'])->toBe(['coolify-db']);
});
