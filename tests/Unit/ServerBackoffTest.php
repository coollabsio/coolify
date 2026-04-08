<?php

use App\Jobs\ServerCheckJob;
use App\Jobs\ServerConnectionCheckJob;
use App\Jobs\ServerManagerJob;
use App\Models\Server;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Carbon;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Carbon::setTestNow('2025-01-15 12:00:00');
});

afterEach(function () {
    Mockery::close();
    Carbon::setTestNow();
});

describe('getBackoffCycleInterval', function () {
    it('returns correct intervals for unreachable counts', function () {
        $job = new ServerManagerJob;
        $method = new ReflectionMethod($job, 'getBackoffCycleInterval');

        expect($method->invoke($job, 0))->toBe(1)
            ->and($method->invoke($job, 1))->toBe(1)
            ->and($method->invoke($job, 2))->toBe(1)
            ->and($method->invoke($job, 3))->toBe(3)
            ->and($method->invoke($job, 5))->toBe(3)
            ->and($method->invoke($job, 6))->toBe(6)
            ->and($method->invoke($job, 11))->toBe(6)
            ->and($method->invoke($job, 12))->toBe(12)
            ->and($method->invoke($job, 100))->toBe(12);
    });
});

describe('shouldSkipDueToBackoff', function () {
    it('never skips servers with unreachable_count <= 2', function () {
        $job = new ServerManagerJob;
        $executionTimeProp = new ReflectionProperty($job, 'executionTime');
        $method = new ReflectionMethod($job, 'shouldSkipDueToBackoff');

        $server = Mockery::mock(Server::class)->makePartial();
        $server->id = 42;

        foreach ([0, 1, 2] as $count) {
            $server->unreachable_count = $count;

            // Test across all minutes in an hour
            for ($minute = 0; $minute < 60; $minute++) {
                Carbon::setTestNow("2025-01-15 12:{$minute}:00");
                $executionTimeProp->setValue($job, Carbon::now());

                expect($method->invoke($job, $server))->toBeFalse(
                    "Should not skip with unreachable_count={$count} at minute={$minute}"
                );
            }
        }
    });

    it('skips most cycles for servers with high unreachable count', function () {
        $job = new ServerManagerJob;
        $executionTimeProp = new ReflectionProperty($job, 'executionTime');
        $method = new ReflectionMethod($job, 'shouldSkipDueToBackoff');

        $server = Mockery::mock(Server::class)->makePartial();
        $server->id = 42;
        $server->unreachable_count = 15; // interval = 12

        $skipCount = 0;
        $allowCount = 0;

        for ($minute = 0; $minute < 60; $minute++) {
            Carbon::setTestNow("2025-01-15 12:{$minute}:00");
            $executionTimeProp->setValue($job, Carbon::now());

            if ($method->invoke($job, $server)) {
                $skipCount++;
            } else {
                $allowCount++;
            }
        }

        // With interval=12, most cycles should be skipped but at least one should be allowed
        expect($allowCount)->toBeGreaterThan(0)
            ->and($skipCount)->toBeGreaterThan($allowCount);
    });

    it('distributes checks across servers using server ID hash', function () {
        $job = new ServerManagerJob;
        $executionTimeProp = new ReflectionProperty($job, 'executionTime');
        $method = new ReflectionMethod($job, 'shouldSkipDueToBackoff');

        // Two servers with same unreachable_count but different IDs
        $server1 = Mockery::mock(Server::class)->makePartial();
        $server1->id = 1;
        $server1->unreachable_count = 5; // interval = 3

        $server2 = Mockery::mock(Server::class)->makePartial();
        $server2->id = 2;
        $server2->unreachable_count = 5; // interval = 3

        $server1AllowedMinutes = [];
        $server2AllowedMinutes = [];

        for ($minute = 0; $minute < 60; $minute++) {
            Carbon::setTestNow("2025-01-15 12:{$minute}:00");
            $executionTimeProp->setValue($job, Carbon::now());

            if (! $method->invoke($job, $server1)) {
                $server1AllowedMinutes[] = $minute;
            }
            if (! $method->invoke($job, $server2)) {
                $server2AllowedMinutes[] = $minute;
            }
        }

        // Both servers should have some allowed minutes, but not all the same
        expect($server1AllowedMinutes)->not->toBeEmpty()
            ->and($server2AllowedMinutes)->not->toBeEmpty()
            ->and($server1AllowedMinutes)->not->toBe($server2AllowedMinutes);
    });
});

describe('ServerConnectionCheckJob unreachable_count', function () {
    it('increments unreachable_count on timeout', function () {
        $settings = Mockery::mock();
        $settings->shouldReceive('update')
            ->with(['is_reachable' => false, 'is_usable' => false])
            ->once();

        $server = Mockery::mock(Server::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $server->shouldReceive('getAttribute')->with('settings')->andReturn($settings);
        $server->shouldReceive('increment')->with('unreachable_count')->once();
        $server->id = 1;
        $server->name = 'test-server';

        $job = new ServerConnectionCheckJob($server);
        $job->failed(new TimeoutExceededException);
    });

    it('does not increment unreachable_count for non-timeout failures', function () {
        $server = Mockery::mock(Server::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $server->shouldNotReceive('increment');
        $server->id = 1;
        $server->name = 'test-server';

        $job = new ServerConnectionCheckJob($server);
        $job->failed(new RuntimeException('Some other error'));
    });
});

describe('ServerCheckJob unreachable_count', function () {
    it('increments unreachable_count on timeout', function () {
        $server = Mockery::mock(Server::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $server->shouldReceive('increment')->with('unreachable_count')->once();
        $server->id = 1;
        $server->name = 'test-server';

        $job = new ServerCheckJob($server);
        $job->failed(new TimeoutExceededException);
    });

    it('does not increment unreachable_count for non-timeout failures', function () {
        $server = Mockery::mock(Server::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $server->shouldNotReceive('increment');
        $server->id = 1;
        $server->name = 'test-server';

        $job = new ServerCheckJob($server);
        $job->failed(new RuntimeException('Some other error'));
    });
});
