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

it('returns error when pgbackrest is not enabled', function () {
    $this->database->shouldReceive('isPgbackrestEnabled')->andReturn(false);

    $action = new RestoreFromPgbackrest;
    $result = $action->handle($this->database);

    expect($result)->toBeArray();
    expect($result['success'])->toBeFalse();
    expect($result['message'])->toBe('pgBackRest is not enabled');
});

it('validates restore returns error when pgbackrest is not enabled', function () {
    $this->database->shouldReceive('isPgbackrestEnabled')->andReturn(false);

    $action = new RestoreFromPgbackrest;
    $result = $action->validateRestore($this->database);

    expect($result)->toBeArray();
    expect($result['valid'])->toBeFalse();
    expect($result['message'])->toBe('pgBackRest is not enabled');
});

it('validates restore returns error when database is running', function () {
    $this->database->shouldReceive('isPgbackrestEnabled')->andReturn(true);
    $this->database->status = 'running:healthy';

    $action = new RestoreFromPgbackrest;
    $result = $action->validateRestore($this->database);

    expect($result)->toBeArray();
    expect($result['valid'])->toBeFalse();
    expect($result['message'])->toContain('must be stopped');
});

it('validates restore passes when database is stopped', function () {
    $this->database->shouldReceive('isPgbackrestEnabled')->andReturn(true);
    $this->database->status = 'exited:unhealthy';

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

it('buildRestoreCommand is called with correct stanza name', function () {
    $action = new RestoreFromPgbackrest;

    $reflection = new ReflectionClass($action);
    $method = $reflection->getMethod('buildRestoreCommand');
    $method->setAccessible(true);

    $command = $method->invoke($action, 'db-test-uuid');

    expect($command)->toContain('--stanza=\'db-test-uuid\'');
    expect($command)->toContain('--type=immediate');
    expect($command)->toContain('--target-action=promote');
    expect($command)->toContain('--delta restore');
});

it('buildRestoreCommand includes backup label when provided', function () {
    $action = new RestoreFromPgbackrest;

    $reflection = new ReflectionClass($action);
    $method = $reflection->getMethod('buildRestoreCommand');
    $method->setAccessible(true);

    $command = $method->invoke($action, 'db-test-uuid', 'backup-20241201-120000F');

    expect($command)->toContain('--set=\'backup-20241201-120000F\'');
});

it('buildRestoreCommand includes target time when provided', function () {
    $action = new RestoreFromPgbackrest;

    $reflection = new ReflectionClass($action);
    $method = $reflection->getMethod('buildRestoreCommand');
    $method->setAccessible(true);

    $command = $method->invoke($action, 'db-test-uuid', null, '2024-12-01 12:00:00');

    expect($command)->toContain('--type=time');
    expect($command)->toContain('--target=\'2024-12-01 12:00:00\'');
});

it('buildRestoreCommand includes target database when provided', function () {
    $action = new RestoreFromPgbackrest;

    $reflection = new ReflectionClass($action);
    $method = $reflection->getMethod('buildRestoreCommand');
    $method->setAccessible(true);

    $command = $method->invoke($action, 'db-test-uuid', null, null, 'mydb');

    expect($command)->toContain('--db-include=\'mydb\'');
});

it('buildRestoreCommand combines all options correctly', function () {
    $action = new RestoreFromPgbackrest;

    $reflection = new ReflectionClass($action);
    $method = $reflection->getMethod('buildRestoreCommand');
    $method->setAccessible(true);

    $command = $method->invoke($action, 'db-test-uuid', 'backup-label', '2024-12-01 12:00:00', 'mydb');

    expect($command)->toContain('--stanza=\'db-test-uuid\'');
    expect($command)->toContain('--set=\'backup-label\'');
    expect($command)->toContain('--type=time');
    expect($command)->toContain('--target=\'2024-12-01 12:00:00\'');
    expect($command)->toContain('--db-include=\'mydb\'');
    expect($command)->toContain('--target-action=promote');
    expect($command)->toContain('--delta restore');
});

it('buildRestoreCommand uses type=immediate when no target time provided', function () {
    $action = new RestoreFromPgbackrest;

    $reflection = new ReflectionClass($action);
    $method = $reflection->getMethod('buildRestoreCommand');
    $method->setAccessible(true);

    $command = $method->invoke($action, 'db-test-uuid', 'backup-label', null, null);

    expect($command)->toContain('--type=immediate');
    expect($command)->not->toContain('--type=time');
});
