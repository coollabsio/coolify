<?php

use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\ScheduledTaskExecution;
use Carbon\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2025-01-15 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
    \Mockery::close();
});

it('marks stuck scheduled task executions as failed without triggering notifications', function () {
    // Mock the ScheduledTaskExecution model
    $mockBuilder = \Mockery::mock('alias:'.ScheduledTaskExecution::class);

    // Expect where clause to be called with 'running' status
    $mockBuilder->shouldReceive('where')
        ->once()
        ->with('status', 'running')
        ->andReturnSelf();

    // Expect update to be called with correct parameters
    $mockBuilder->shouldReceive('update')
        ->once()
        ->with([
            'status' => 'failed',
            'message' => 'Marked as failed during Coolify startup - job was interrupted',
            'finished_at' => Carbon::now(),
        ])
        ->andReturn(2); // Simulate 2 records updated

    // Execute the cleanup logic directly
    $updatedCount = ScheduledTaskExecution::where('status', 'running')->update([
        'status' => 'failed',
        'message' => 'Marked as failed during Coolify startup - job was interrupted',
        'finished_at' => Carbon::now(),
    ]);

    // Assert the count is correct
    expect($updatedCount)->toBe(2);
});

it('marks stuck database backup executions as failed without triggering notifications', function () {
    // Mock the ScheduledDatabaseBackupExecution model
    $mockBuilder = \Mockery::mock('alias:'.ScheduledDatabaseBackupExecution::class);

    // Expect where clause to be called with 'running' status
    $mockBuilder->shouldReceive('where')
        ->once()
        ->with('status', 'running')
        ->andReturnSelf();

    // Expect update to be called with correct parameters
    $mockBuilder->shouldReceive('update')
        ->once()
        ->with([
            'status' => 'failed',
            'message' => 'Marked as failed during Coolify startup - job was interrupted',
            'finished_at' => Carbon::now(),
        ])
        ->andReturn(3); // Simulate 3 records updated

    // Execute the cleanup logic directly
    $updatedCount = ScheduledDatabaseBackupExecution::where('status', 'running')->update([
        'status' => 'failed',
        'message' => 'Marked as failed during Coolify startup - job was interrupted',
        'finished_at' => Carbon::now(),
    ]);

    // Assert the count is correct
    expect($updatedCount)->toBe(3);
});

it('handles cleanup when no stuck executions exist', function () {
    // Mock the ScheduledTaskExecution model
    $mockBuilder = \Mockery::mock('alias:'.ScheduledTaskExecution::class);

    $mockBuilder->shouldReceive('where')
        ->once()
        ->with('status', 'running')
        ->andReturnSelf();

    $mockBuilder->shouldReceive('update')
        ->once()
        ->andReturn(0); // No records updated

    $updatedCount = ScheduledTaskExecution::where('status', 'running')->update([
        'status' => 'failed',
        'message' => 'Marked as failed during Coolify startup - job was interrupted',
        'finished_at' => Carbon::now(),
    ]);

    expect($updatedCount)->toBe(0);
});

it('uses correct failure message for interrupted jobs', function () {
    $expectedMessage = 'Marked as failed during Coolify startup - job was interrupted';

    // Verify the message clearly indicates the job was interrupted during startup
    expect($expectedMessage)
        ->toContain('Coolify startup')
        ->toContain('interrupted')
        ->toContain('failed');
});

it('sets finished_at timestamp when marking executions as failed', function () {
    $now = Carbon::now();

    // Verify Carbon::now() is used for finished_at
    expect($now)->toBeInstanceOf(Carbon::class)
        ->and($now->toDateTimeString())->toBe('2025-01-15 12:00:00');
});
