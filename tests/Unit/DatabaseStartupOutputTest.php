<?php

it('formats image progress output consistently', function (string $action) {
    $source = file_get_contents(__DIR__."/../../app/Actions/Database/{$action}.php");

    expect($source)->toContain('$this->commands[] = \'echo \'.escapeshellarg("Pulling {$database->image} image.");');
})->with([
    'postgresql' => 'StartPostgresql',
    'clickhouse' => 'StartClickhouse',
    'dragonfly' => 'StartDragonfly',
    'keydb' => 'StartKeydb',
    'mariadb' => 'StartMariadb',
    'mongodb' => 'StartMongodb',
    'mysql' => 'StartMysql',
    'redis' => 'StartRedis',
]);

it('prints image names as text', function (string $image) {
    $message = "Pulling {$image} image.";

    expect(shell_exec('echo '.escapeshellarg($message)))->toBe($message."\n");
})->with([
    'apostrophe' => "example:v1's",
    'parentheses' => 'example:v1 (preview)',
]);
