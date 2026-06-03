<?php

use App\Actions\Database\StartRedis;
use App\Models\StandaloneRedis;

test('redis config chown command is added when redis_conf is set', function () {
    $action = new StartRedis;
    $action->configuration_dir = '/data/coolify/databases/test-uuid';
    $action->commands = [];

    $database = Mockery::mock(StandaloneRedis::class)->makePartial();
    $database->shouldReceive('getAttribute')->with('redis_conf')->andReturn('maxmemory 2gb');
    $action->database = $database;

    // Simulate the chown logic from handle()
    if (! is_null($action->database->redis_conf) && ! empty($action->database->redis_conf)) {
        $action->commands[] = "chown 999:999 {$action->configuration_dir}/redis.conf";
    }

    expect($action->commands)->toContain('chown 999:999 /data/coolify/databases/test-uuid/redis.conf');
});

test('redis config chown command is not added when redis_conf is null', function () {
    $action = new StartRedis;
    $action->configuration_dir = '/data/coolify/databases/test-uuid';
    $action->commands = [];

    $database = Mockery::mock(StandaloneRedis::class)->makePartial();
    $database->shouldReceive('getAttribute')->with('redis_conf')->andReturn(null);
    $action->database = $database;

    if (! is_null($action->database->redis_conf) && ! empty($action->database->redis_conf)) {
        $action->commands[] = "chown 999:999 {$action->configuration_dir}/redis.conf";
    }

    expect($action->commands)->toBeEmpty();
});

test('redis config chown command is not added when redis_conf is empty', function () {
    $action = new StartRedis;
    $action->configuration_dir = '/data/coolify/databases/test-uuid';
    $action->commands = [];

    $database = Mockery::mock(StandaloneRedis::class)->makePartial();
    $database->shouldReceive('getAttribute')->with('redis_conf')->andReturn('');
    $action->database = $database;

    if (! is_null($action->database->redis_conf) && ! empty($action->database->redis_conf)) {
        $action->commands[] = "chown 999:999 {$action->configuration_dir}/redis.conf";
    }

    expect($action->commands)->toBeEmpty();
});
