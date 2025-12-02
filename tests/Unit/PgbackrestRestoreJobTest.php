<?php

use App\Jobs\PgbackrestRestoreJob;
use App\Models\StandalonePostgresql;
use App\Services\PgbackrestService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;

beforeEach(function () {
    $this->database = Mockery::mock(StandalonePostgresql::class)->makePartial();
    $this->database->shouldReceive('getPgbackrestStanzaName')->andReturn('db-test-uuid');
});

afterEach(function () {
    Mockery::close();
});

it('implements required queue interfaces', function () {
    $job = new PgbackrestRestoreJob($this->database);

    $interfaces = class_implements($job);
    expect($interfaces)->toContain(ShouldQueue::class);
    expect($interfaces)->toContain(ShouldBeEncrypted::class);
});

it('has correct job configuration', function () {
    $job = new PgbackrestRestoreJob($this->database);

    expect($job->timeout)->toBe(7200);
    expect($job->tries)->toBe(1);
});

it('queues on high priority queue', function () {
    $job = new PgbackrestRestoreJob($this->database);

    expect($job->queue)->toBe('high');
});

it('accepts optional backup label parameter', function () {
    $job = new PgbackrestRestoreJob($this->database, 'backup-20241201-120000F');

    expect($job->backupLabel)->toBe('backup-20241201-120000F');
});

it('accepts optional target time parameter', function () {
    $job = new PgbackrestRestoreJob($this->database, null, '2024-12-01 12:00:00');

    expect($job->targetTime)->toBe('2024-12-01 12:00:00');
});

it('has restart after option defaulting to true', function () {
    $job = new PgbackrestRestoreJob($this->database);

    expect($job->restartAfter)->toBeTrue();
});

it('can disable restart after restore', function () {
    $job = new PgbackrestRestoreJob($this->database, null, null, false);

    expect($job->restartAfter)->toBeFalse();
});

it('service buildRestoreCommand includes type=immediate when no target time', function () {
    $service = PgbackrestService::for($this->database);

    $command = $service->buildRestoreCommand('backup-label');

    expect($command)->toContain('--type=immediate');
    expect($command)->toContain('--target-action=promote');
    expect($command)->toContain('--delta');
    expect($command)->toContain('--link-all');
});

it('service buildRestoreCommand includes type=time when target time is provided', function () {
    $service = PgbackrestService::for($this->database);

    $command = $service->buildRestoreCommand('backup-label', '2024-12-01 12:00:00');

    expect($command)->toContain('--type=time');
    expect($command)->toContain("--target='2024-12-01 12:00:00'");
    expect($command)->not->toContain('--type=immediate');
});

it('service buildRestoreCommand includes backup label when provided', function () {
    $service = PgbackrestService::for($this->database);

    $command = $service->buildRestoreCommand('my-backup-20241201');

    expect($command)->toContain("--set='my-backup-20241201'");
});
