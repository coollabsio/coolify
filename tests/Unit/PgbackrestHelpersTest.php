<?php

use App\Models\StandalonePostgresql;

beforeEach(function () {
    $this->database = Mockery::mock(StandalonePostgresql::class)->makePartial();
    $this->database->shouldReceive('isPgbackrestEnabled')->andReturn(true);
    $this->database->shouldReceive('getPgbackrestContainerName')->andReturn('test-uuid-pgbackrest');
    $this->database->shouldReceive('getPgbackrestStanzaName')->andReturn('db-test-uuid');
});

afterEach(function () {
    Mockery::close();
});

it('formatPgbackrestBackupType returns correct labels', function () {
    expect(formatPgbackrestBackupType('full'))->toBe('Full');
    expect(formatPgbackrestBackupType('diff'))->toBe('Differential');
    expect(formatPgbackrestBackupType('incr'))->toBe('Incremental');
    expect(formatPgbackrestBackupType('unknown'))->toBe('Unknown');
});

it('formatPgbackrestBackupType handles edge cases', function () {
    expect(formatPgbackrestBackupType(''))->toBe('');
    expect(formatPgbackrestBackupType('FULL'))->toBe('FULL');
});

it('getPgbackrestInfo returns null when pgbackrest is disabled', function () {
    $database = Mockery::mock(StandalonePostgresql::class)->makePartial();
    $database->shouldReceive('isPgbackrestEnabled')->andReturn(false);

    expect(getPgbackrestInfo($database))->toBeNull();
});

it('getPgbackrestInfo returns null when server is null', function () {
    $destination = new stdClass;
    $destination->server = null;

    $this->database->destination = $destination;

    expect(getPgbackrestInfo($this->database))->toBeNull();
});

it('isPgbackrestContainerRunning returns false when server is null', function () {
    $destination = new stdClass;
    $destination->server = null;

    $this->database->destination = $destination;

    expect(isPgbackrestContainerRunning($this->database))->toBeFalse();
});

it('getPgbackrestBackupList returns empty collection when info is null', function () {
    $database = Mockery::mock(StandalonePostgresql::class)->makePartial();
    $database->shouldReceive('isPgbackrestEnabled')->andReturn(false);

    $result = getPgbackrestBackupList($database);

    expect($result)->toBeInstanceOf(\Illuminate\Support\Collection::class);
    expect($result->isEmpty())->toBeTrue();
});

it('getPgbackrestLatestBackup returns null when no backups exist', function () {
    $database = Mockery::mock(StandalonePostgresql::class)->makePartial();
    $database->shouldReceive('isPgbackrestEnabled')->andReturn(false);

    expect(getPgbackrestLatestBackup($database))->toBeNull();
});

it('getPgbackrestBackupByLabel returns null when no backups exist', function () {
    $database = Mockery::mock(StandalonePostgresql::class)->makePartial();
    $database->shouldReceive('isPgbackrestEnabled')->andReturn(false);

    expect(getPgbackrestBackupByLabel($database, 'some-label'))->toBeNull();
});

it('getPgbackrestStanzaStatus returns disabled when pgbackrest is not enabled', function () {
    $database = Mockery::mock(StandalonePostgresql::class)->makePartial();
    $database->shouldReceive('isPgbackrestEnabled')->andReturn(false);

    $result = getPgbackrestStanzaStatus($database);

    expect($result)->toBeArray();
    expect($result['status'])->toBe('disabled');
    expect($result['message'])->toBe('pgBackRest is not enabled');
});

it('calculatePgbackrestTotalSize returns 0 when no backups exist', function () {
    $database = Mockery::mock(StandalonePostgresql::class)->makePartial();
    $database->shouldReceive('isPgbackrestEnabled')->andReturn(false);

    expect(calculatePgbackrestTotalSize($database))->toBe(0);
});
