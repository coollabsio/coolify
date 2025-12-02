<?php

use App\Actions\Database\Pgbackrest\RestoreFromPgbackrest;
use App\Models\StandalonePostgresql;

beforeEach(function () {
    $this->database = Mockery::mock(StandalonePostgresql::class)->makePartial();
    $this->database->shouldReceive('getPgbackrestStanzaName')->andReturn('db-test-uuid');
});

afterEach(function () {
    Mockery::close();
});

it('validates restore returns error when pgbackrest is not enabled', function () {
    $this->database->shouldReceive('isPgbackrestEnabled')->andReturn(false);

    $action = new RestoreFromPgbackrest;
    $result = $action->validateRestore($this->database);

    expect($result)->toBeArray();
    expect($result['valid'])->toBeFalse();
    expect($result['message'])->toBe('pgBackRest is not enabled');
});

it('validates restore passes when pgbackrest is enabled', function () {
    $this->database->shouldReceive('isPgbackrestEnabled')->andReturn(true);

    $action = new RestoreFromPgbackrest;
    $result = $action->validateRestore($this->database);

    expect($result)->toBeArray();
    expect($result['valid'])->toBeTrue();
});

it('getAvailableBackups returns error when pgbackrest is not enabled', function () {
    $this->database->shouldReceive('isPgbackrestEnabled')->andReturn(false);

    $action = new RestoreFromPgbackrest;
    $result = $action->getAvailableBackups($this->database);

    expect($result)->toBeArray();
    expect($result['success'])->toBeFalse();
    expect($result['message'])->toBe('pgBackRest is not enabled');
    expect($result['backups'])->toBe([]);
});
