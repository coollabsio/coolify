<?php

use App\Models\ScheduledDatabaseBackup;
use Mockery;

it('checks if backup is pgBackRest type', function () {
    $backup = Mockery::mock(ScheduledDatabaseBackup::class)->makePartial();
    $backup->backup_type = 'pgbackrest';

    expect($backup->isPgBackRest())->toBeTrue();
});

it('checks if backup is pg_dump type', function () {
    $backup = Mockery::mock(ScheduledDatabaseBackup::class)->makePartial();
    $backup->backup_type = 'pg_dump';

    expect($backup->isPgDump())->toBeTrue();
});

it('defaults to pg_dump when backup_type is null', function () {
    $backup = Mockery::mock(ScheduledDatabaseBackup::class)->makePartial();
    $backup->backup_type = null;

    expect($backup->isPgDump())->toBeTrue();
});

it('generates stanza name from custom stanza', function () {
    $backup = Mockery::mock(ScheduledDatabaseBackup::class)->makePartial();
    $backup->pgbackrest_stanza = 'my-custom-stanza';
    $backup->uuid = 'test-uuid-123';

    expect($backup->getStanzaName())->toBe('my-custom-stanza');
});

it('auto-generates stanza name from database name', function () {
    $backup = Mockery::mock(ScheduledDatabaseBackup::class)->makePartial();
    $backup->pgbackrest_stanza = null;
    $backup->uuid = 'test-uuid-123';

    $database = Mockery::mock();
    $database->name = 'production_db';
    $backup->shouldReceive('getAttribute')->with('database')->andReturn($database);

    $stanzaName = $backup->getStanzaName();

    expect($stanzaName)->toContain('coolify-production-db');
    expect($stanzaName)->toContain('test-uuid-123');
});

it('returns default backup type as full', function () {
    $backup = Mockery::mock(ScheduledDatabaseBackup::class)->makePartial();
    $backup->pgbackrest_type = null;

    expect($backup->getPgBackRestType())->toBe('full');
});

it('returns configured backup type', function () {
    $backup = Mockery::mock(ScheduledDatabaseBackup::class)->makePartial();
    $backup->pgbackrest_type = 'incr';

    expect($backup->getPgBackRestType())->toBe('incr');
});

it('supports differential backup type', function () {
    $backup = Mockery::mock(ScheduledDatabaseBackup::class)->makePartial();
    $backup->pgbackrest_type = 'diff';

    expect($backup->getPgBackRestType())->toBe('diff');
});