<?php

use App\Actions\Database\StartKeydb;
use App\Models\StandaloneKeydb;

test('keydb config chown command is added when keydb_conf is set', function () {
    $action = new StartKeydb;
    $action->configuration_dir = '/data/coolify/databases/test-uuid';
    $action->commands = [];

    $database = Mockery::mock(StandaloneKeydb::class)->makePartial();
    $database->shouldReceive('getAttribute')->with('keydb_conf')->andReturn('maxmemory 2gb');
    $action->database = $database;

    if (! is_null($action->database->keydb_conf) && ! empty($action->database->keydb_conf)) {
        $action->commands[] = "chown 999:999 {$action->configuration_dir}/keydb.conf";
    }

    expect($action->commands)->toContain('chown 999:999 /data/coolify/databases/test-uuid/keydb.conf');
});

test('keydb config chown command is not added when keydb_conf is null', function () {
    $action = new StartKeydb;
    $action->configuration_dir = '/data/coolify/databases/test-uuid';
    $action->commands = [];

    $database = Mockery::mock(StandaloneKeydb::class)->makePartial();
    $database->shouldReceive('getAttribute')->with('keydb_conf')->andReturn(null);
    $action->database = $database;

    if (! is_null($action->database->keydb_conf) && ! empty($action->database->keydb_conf)) {
        $action->commands[] = "chown 999:999 {$action->configuration_dir}/keydb.conf";
    }

    expect($action->commands)->toBeEmpty();
});

test('keydb config chown command is not added when keydb_conf is empty', function () {
    $action = new StartKeydb;
    $action->configuration_dir = '/data/coolify/databases/test-uuid';
    $action->commands = [];

    $database = Mockery::mock(StandaloneKeydb::class)->makePartial();
    $database->shouldReceive('getAttribute')->with('keydb_conf')->andReturn('');
    $action->database = $database;

    if (! is_null($action->database->keydb_conf) && ! empty($action->database->keydb_conf)) {
        $action->commands[] = "chown 999:999 {$action->configuration_dir}/keydb.conf";
    }

    expect($action->commands)->toBeEmpty();
});
