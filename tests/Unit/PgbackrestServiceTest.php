<?php

use App\Models\StandalonePostgresql;
use App\Services\PgbackrestService;

beforeEach(function () {
    $this->database = Mockery::mock(StandalonePostgresql::class)->makePartial();
    $this->database->shouldReceive('getPgbackrestStanzaName')->andReturn('db-test-uuid');
    $this->database->shouldReceive('isPgbackrestEnabled')->andReturn(true);
    $this->database->uuid = 'test-container-uuid';
    $this->database->image = 'postgres:16-alpine';
});

afterEach(function () {
    Mockery::close();
});

it('can be instantiated with static for method', function () {
    $service = PgbackrestService::for($this->database);

    expect($service)->toBeInstanceOf(PgbackrestService::class);
});

it('returns stanza name from database', function () {
    $service = PgbackrestService::for($this->database);

    expect($service->getStanzaName())->toBe('db-test-uuid');
});

it('isEnabled delegates to database method', function () {
    $service = PgbackrestService::for($this->database);

    expect($service->isEnabled())->toBeTrue();

    $disabledDb = Mockery::mock(StandalonePostgresql::class)->makePartial();
    $disabledDb->shouldReceive('isPgbackrestEnabled')->andReturn(false);

    $service2 = PgbackrestService::for($disabledDb);
    expect($service2->isEnabled())->toBeFalse();
});

it('returns false for isContainerRunning when server is null', function () {
    $destination = new stdClass;
    $destination->server = null;
    $this->database->destination = $destination;

    $service = PgbackrestService::for($this->database);

    expect($service->isContainerRunning())->toBeFalse();
});

it('getMounts returns null values when server is null', function () {
    $destination = new stdClass;
    $destination->server = null;
    $this->database->destination = $destination;

    $service = PgbackrestService::for($this->database);
    $mounts = $service->getMounts();

    expect($mounts)->toBeArray();
    expect($mounts['data_volume'])->toBeNull();
    expect($mounts['pgbackrest_config'])->toBeNull();
    expect($mounts['pgbackrest_repo'])->toBeNull();
});

it('getInfo returns null when pgbackrest is disabled', function () {
    $database = Mockery::mock(StandalonePostgresql::class)->makePartial();
    $database->shouldReceive('isPgbackrestEnabled')->andReturn(false);

    $service = PgbackrestService::for($database);

    expect($service->getInfo())->toBeNull();
});

it('getBackupList returns empty collection when info is null', function () {
    $database = Mockery::mock(StandalonePostgresql::class)->makePartial();
    $database->shouldReceive('isPgbackrestEnabled')->andReturn(false);

    $service = PgbackrestService::for($database);
    $result = $service->getBackupList();

    expect($result)->toBeInstanceOf(\Illuminate\Support\Collection::class);
    expect($result->isEmpty())->toBeTrue();
});

it('validateRestore returns error when pgbackrest is disabled', function () {
    $database = Mockery::mock(StandalonePostgresql::class)->makePartial();
    $database->shouldReceive('isPgbackrestEnabled')->andReturn(false);

    $service = PgbackrestService::for($database);
    $result = $service->validateRestore();

    expect($result['valid'])->toBeFalse();
    expect($result['message'])->toBe('pgBackRest is not enabled');
});

it('validateRestore passes when pgbackrest is enabled and no label', function () {
    $destination = new stdClass;
    $destination->server = null;
    $this->database->destination = $destination;

    $service = PgbackrestService::for($this->database);
    $result = $service->validateRestore();

    expect($result['valid'])->toBeTrue();
    expect($result['message'])->toBe('Restore can proceed');
});

it('buildRestoreCommand includes stanza', function () {
    $service = PgbackrestService::for($this->database);

    $command = $service->buildRestoreCommand();

    expect($command)->toContain("--stanza='db-test-uuid'");
    expect($command)->toContain('restore');
});

it('buildRestoreCommand includes backup label when provided', function () {
    $service = PgbackrestService::for($this->database);

    $command = $service->buildRestoreCommand('my-backup-label');

    expect($command)->toContain("--set='my-backup-label'");
});

it('buildRestoreCommand includes target time when provided', function () {
    $service = PgbackrestService::for($this->database);

    $command = $service->buildRestoreCommand(null, '2024-12-01 12:00:00');

    expect($command)->toContain('--type=time');
    expect($command)->toContain("--target='2024-12-01 12:00:00'");
});

it('buildRestoreCommand uses immediate type when no target time', function () {
    $service = PgbackrestService::for($this->database);

    $command = $service->buildRestoreCommand();

    expect($command)->toContain('--type=immediate');
});

it('buildRestoreCommand includes paths when includePaths is true', function () {
    $mockRelation = Mockery::mock();
    $mockRelation->shouldReceive('where')->with('type', 'posix')->andReturnSelf();
    $mockRelation->shouldReceive('exists')->andReturn(true);
    $this->database->shouldReceive('pgbackrestRepos')->andReturn($mockRelation);

    $service = PgbackrestService::for($this->database);

    $command = $service->buildRestoreCommand(null, null, includePaths: true);

    expect($command)->toContain('--pg1-path=/var/lib/postgresql/data');
    expect($command)->toContain('--repo1-path=/var/lib/pgbackrest');
});

it('buildRestoreCommand excludes paths by default', function () {
    $service = PgbackrestService::for($this->database);

    $command = $service->buildRestoreCommand();

    expect($command)->not->toContain('--pg1-path');
    expect($command)->not->toContain('--repo1-path');
});

it('formatBackupType returns correct labels', function () {
    expect(PgbackrestService::formatBackupType('full'))->toBe('Full');
    expect(PgbackrestService::formatBackupType('diff'))->toBe('Differential');
    expect(PgbackrestService::formatBackupType('incr'))->toBe('Incremental');
    expect(PgbackrestService::formatBackupType('unknown'))->toBe('Unknown');
});

it('formatErrorMessage extracts FATAL errors', function () {
    $exception = new \RuntimeException('Some prefix FATAL: connection refused');

    $message = PgbackrestService::formatErrorMessage($exception);

    expect($message)->toContain('pgBackRest Error:');
    expect($message)->toContain('FATAL');
});

it('formatErrorMessage handles archive_mode errors', function () {
    $exception = new \RuntimeException('archive_mode must be enabled');

    $message = PgbackrestService::formatErrorMessage($exception);

    expect($message)->toContain('not configured for archiving');
});

it('formatErrorMessage truncates long messages', function () {
    $longMessage = str_repeat('a', 600);
    $exception = new \RuntimeException($longMessage);

    $message = PgbackrestService::formatErrorMessage($exception);

    expect(strlen($message))->toBeLessThanOrEqual(503);
    expect($message)->toEndWith('...');
});

it('getStanzaStatus returns disabled when pgbackrest is not enabled', function () {
    $database = Mockery::mock(StandalonePostgresql::class)->makePartial();
    $database->shouldReceive('isPgbackrestEnabled')->andReturn(false);

    $service = PgbackrestService::for($database);
    $result = $service->getStanzaStatus();

    expect($result['status'])->toBe('disabled');
    expect($result['message'])->toBe('pgBackRest is not enabled');
});

it('getTotalSize returns 0 when no backups', function () {
    $database = Mockery::mock(StandalonePostgresql::class)->makePartial();
    $database->shouldReceive('isPgbackrestEnabled')->andReturn(false);

    $service = PgbackrestService::for($database);

    expect($service->getTotalSize())->toBe(0);
});

it('isBackupDeletable returns not found when backup does not exist', function () {
    $database = Mockery::mock(StandalonePostgresql::class)->makePartial();
    $database->shouldReceive('isPgbackrestEnabled')->andReturn(false);

    $service = PgbackrestService::for($database);
    $result = $service->isBackupDeletable('nonexistent-label');

    expect($result['deletable'])->toBeFalse();
    expect($result['reason'])->toBe('Backup not found in repository');
});

it('backupExists returns false when backup not found', function () {
    $database = Mockery::mock(StandalonePostgresql::class)->makePartial();
    $database->shouldReceive('isPgbackrestEnabled')->andReturn(false);

    $service = PgbackrestService::for($database);

    expect($service->backupExists('nonexistent'))->toBeFalse();
});

it('clearMountCache returns self for chaining', function () {
    $service = PgbackrestService::for($this->database);

    $result = $service->clearMountCache();

    expect($result)->toBe($service);
});

it('isS3Repo returns true when S3 repo exists', function () {
    $mockRelation = Mockery::mock();
    $mockRelation->shouldReceive('where')->with('type', 's3')->andReturnSelf();
    $mockRelation->shouldReceive('exists')->andReturn(true);
    $this->database->shouldReceive('pgbackrestRepos')->andReturn($mockRelation);

    $service = PgbackrestService::for($this->database);
    expect($service->isS3Repo())->toBeTrue();
});

it('isS3Repo returns false when no S3 repo exists', function () {
    $mockRelation = Mockery::mock();
    $mockRelation->shouldReceive('where')->with('type', 's3')->andReturnSelf();
    $mockRelation->shouldReceive('exists')->andReturn(false);
    $this->database->shouldReceive('pgbackrestRepos')->andReturn($mockRelation);

    $service = PgbackrestService::for($this->database);
    expect($service->isS3Repo())->toBeFalse();
});

it('hasLocalRepo returns true when posix repo exists', function () {
    $mockRelation = Mockery::mock();
    $mockRelation->shouldReceive('where')->with('type', 'posix')->andReturnSelf();
    $mockRelation->shouldReceive('exists')->andReturn(true);
    $this->database->shouldReceive('pgbackrestRepos')->andReturn($mockRelation);

    $service = PgbackrestService::for($this->database);
    expect($service->hasLocalRepo())->toBeTrue();
});

it('hasLocalRepo returns false when no posix repo exists', function () {
    $mockRelation = Mockery::mock();
    $mockRelation->shouldReceive('where')->with('type', 'posix')->andReturnSelf();
    $mockRelation->shouldReceive('exists')->andReturn(false);
    $this->database->shouldReceive('pgbackrestRepos')->andReturn($mockRelation);

    $service = PgbackrestService::for($this->database);
    expect($service->hasLocalRepo())->toBeFalse();
});

it('buildRestoreCommand includes repo1-path when local repo exists', function () {
    $mockRelation = Mockery::mock();
    $mockRelation->shouldReceive('where')->with('type', 'posix')->andReturnSelf();
    $mockRelation->shouldReceive('exists')->andReturn(true);
    $this->database->shouldReceive('pgbackrestRepos')->andReturn($mockRelation);

    $service = PgbackrestService::for($this->database);

    $command = $service->buildRestoreCommand(null, null, includePaths: true);

    expect($command)->toContain('--repo1-path=/var/lib/pgbackrest');
    expect($command)->toContain('--pg1-path=/var/lib/postgresql/data');
});

it('buildRestoreCommand excludes repo1-path when no local repo', function () {
    $mockRelation = Mockery::mock();
    $mockRelation->shouldReceive('where')->with('type', 'posix')->andReturnSelf();
    $mockRelation->shouldReceive('exists')->andReturn(false);
    $this->database->shouldReceive('pgbackrestRepos')->andReturn($mockRelation);

    $service = PgbackrestService::for($this->database);

    $command = $service->buildRestoreCommand(null, null, includePaths: true);

    expect($command)->not->toContain('--repo1-path=/var/lib/pgbackrest');
    expect($command)->toContain('--pg1-path=/var/lib/postgresql/data');
});
