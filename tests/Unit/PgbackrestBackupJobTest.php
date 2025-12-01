<?php

use App\Jobs\PgbackrestBackupJob;
use App\Models\ScheduledDatabaseBackup;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;

it('implements required queue interfaces', function () {
    $backup = new ScheduledDatabaseBackup;
    $backup->setRawAttributes(['timeout' => 3600]);

    $job = new PgbackrestBackupJob($backup);

    $interfaces = class_implements($job);
    expect($interfaces)->toContain(ShouldQueue::class);
    expect($interfaces)->toContain(ShouldBeEncrypted::class);
});

it('has correct job configuration', function () {
    $backup = new ScheduledDatabaseBackup;
    $backup->setRawAttributes(['timeout' => 7200]);

    $job = new PgbackrestBackupJob($backup);

    expect($job->timeout)->toBe(7200);
    expect($job->maxExceptions)->toBe(1);
});

it('uses default timeout when backup timeout is null', function () {
    $backup = new ScheduledDatabaseBackup;
    $backup->setRawAttributes(['timeout' => null]);

    $job = new PgbackrestBackupJob($backup);

    expect($job->timeout)->toBe(3600);
});

it('queues on high priority queue', function () {
    $backup = new ScheduledDatabaseBackup;
    $backup->setRawAttributes(['timeout' => 3600]);

    $job = new PgbackrestBackupJob($backup);

    expect($job->queue)->toBe('high');
});
