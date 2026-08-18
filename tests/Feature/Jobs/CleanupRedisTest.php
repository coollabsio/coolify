<?php

use App\Console\Commands\CleanupRedis;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    config(['horizon.prefix' => 'horizon:']);
});

it('handles Redis scan returning false gracefully', function () {
    // Mock Redis connection
    $redisMock = Mockery::mock();

    // Mock scan() returning false (error case)
    $redisMock->shouldReceive('scan')
        ->once()
        ->with(0, ['match' => '*', 'count' => 100])
        ->andReturn(false);

    // Mock keys() for initial scan and overlapping queues cleanup
    $redisMock->shouldReceive('keys')
        ->with('*')
        ->andReturn([]);

    Redis::shouldReceive('connection')
        ->with('horizon')
        ->andReturn($redisMock);

    // Run the command in dry-run mode with restart flag to trigger cleanupStuckJobs
    // Use skip-overlapping to avoid additional keys() calls
    $this->artisan(CleanupRedis::class, ['--dry-run' => true, '--restart' => true, '--skip-overlapping' => true])
        ->expectsOutput('DRY RUN MODE - No data will be deleted')
        ->expectsOutputToContain('Redis scan failed, stopping key retrieval')
        ->assertSuccessful();
});

it('successfully scans Redis keys when scan returns valid results', function () {
    // Mock Redis connection
    $redisMock = Mockery::mock();

    // Mock successful scan() that returns keys
    // First iteration returns cursor 1 and some keys
    $redisMock->shouldReceive('scan')
        ->once()
        ->with(0, ['match' => '*', 'count' => 100])
        ->andReturn([1, ['horizon:job:1', 'horizon:job:2']]);

    // Second iteration returns cursor 0 (end of scan) and more keys
    $redisMock->shouldReceive('scan')
        ->once()
        ->with(1, ['match' => '*', 'count' => 100])
        ->andReturn([0, ['horizon:job:3']]);

    // Mock keys() for initial scan
    $redisMock->shouldReceive('keys')
        ->with('*')
        ->andReturn([]);

    // Mock command() for type checking on each key
    $redisMock->shouldReceive('command')
        ->with('type', Mockery::any())
        ->andReturn(5); // Hash type

    // Mock command() for hgetall to get job data
    $redisMock->shouldReceive('command')
        ->with('hgetall', Mockery::any())
        ->andReturn([
            'status' => 'processing',
            'reserved_at' => time() - 60, // Started 1 minute ago
            'payload' => json_encode(['displayName' => 'TestJob']),
        ]);

    Redis::shouldReceive('connection')
        ->with('horizon')
        ->andReturn($redisMock);

    // Run the command with restart flag to trigger cleanupStuckJobs
    $this->artisan(CleanupRedis::class, ['--dry-run' => true, '--restart' => true, '--skip-overlapping' => true])
        ->expectsOutput('DRY RUN MODE - No data will be deleted')
        ->assertSuccessful();
});

it('handles empty scan results gracefully', function () {
    // Mock Redis connection
    $redisMock = Mockery::mock();

    // Mock scan() returning empty results
    $redisMock->shouldReceive('scan')
        ->once()
        ->with(0, ['match' => '*', 'count' => 100])
        ->andReturn([0, []]); // Cursor 0 and no keys

    // Mock keys() for initial scan
    $redisMock->shouldReceive('keys')
        ->with('*')
        ->andReturn([]);

    Redis::shouldReceive('connection')
        ->with('horizon')
        ->andReturn($redisMock);

    // Run the command with restart flag
    $this->artisan(CleanupRedis::class, ['--dry-run' => true, '--restart' => true, '--skip-overlapping' => true])
        ->expectsOutput('DRY RUN MODE - No data will be deleted')
        ->assertSuccessful();
});

it('uses lowercase option keys for scan', function () {
    // Mock Redis connection
    $redisMock = Mockery::mock();

    // Verify that scan is called with lowercase keys: 'match' and 'count'
    $redisMock->shouldReceive('scan')
        ->once()
        ->with(0, ['match' => '*', 'count' => 100])
        ->andReturn([0, []]);

    // Mock keys() for initial scan
    $redisMock->shouldReceive('keys')
        ->with('*')
        ->andReturn([]);

    Redis::shouldReceive('connection')
        ->with('horizon')
        ->andReturn($redisMock);

    // Run the command with restart flag
    $this->artisan(CleanupRedis::class, ['--dry-run' => true, '--restart' => true, '--skip-overlapping' => true])
        ->assertSuccessful();
});
