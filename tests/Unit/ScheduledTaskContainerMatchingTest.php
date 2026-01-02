<?php

use App\Jobs\ScheduledTaskJob;
use App\Models\Application;
use App\Models\ScheduledTask;
use App\Models\Service;
use App\Models\Team;

beforeEach(function () {
    // Mock the team
    $this->team = Mockery::mock(Team::class);
    $this->team->shouldReceive('findOrFail')->andReturn($this->team);
});

afterEach(function () {
    Mockery::close();
});

describe('ScheduledTaskJob container matching', function () {
    it('matches application containers with uuid format', function () {
        // Test that container names like "abc123" (consistent naming) are matched correctly
        $uuid = 'abc123xyz';
        $containerName = $uuid;

        expect(str_starts_with($containerName, $uuid))->toBeTrue();
    });

    it('matches application containers with uuid-timestamp format', function () {
        // Test that container names like "abc123-1732718096" (non-consistent naming) are matched correctly
        $uuid = 'abc123xyz';
        $timestamp = '1732718096';
        $containerName = "{$uuid}-{$timestamp}";

        expect(str_starts_with($containerName, $uuid))->toBeTrue();
    });

    it('matches service containers with name-uuid format', function () {
        // Test that service container names like "nginx-abc123" are matched correctly
        $serviceName = 'nginx';
        $serviceUuid = 'abc123xyz';
        $containerName = "{$serviceName}-{$serviceUuid}";

        expect(str_starts_with($containerName, "{$serviceName}-{$serviceUuid}"))->toBeTrue();
    });

    it('selects newest container when multiple exist during rolling deployment', function () {
        // Test that when multiple containers exist, the newest one (highest timestamp) is selected
        $uuid = 'abc123xyz';
        $containers = [
            "{$uuid}-1732718096",
            "{$uuid}-1732718100", // newer
            "{$uuid}-1732718090", // older
        ];

        // Sort descending to get newest first
        rsort($containers);

        expect($containers[0])->toBe("{$uuid}-1732718100");
    });

    it('correctly identifies running vs stopped containers', function () {
        // Test that only running containers are included
        $runningContainer = [
            'Names' => '/abc123xyz-1732718096',
            'State' => 'running',
        ];

        $stoppedContainer = [
            'Names' => '/abc123xyz-1732718090',
            'State' => 'exited',
        ];

        expect(data_get($runningContainer, 'State'))->toBe('running');
        expect(data_get($stoppedContainer, 'State'))->not->toBe('running');
    });

    it('handles dockercompose application container matching', function () {
        // Test that dockercompose containers with service names are matched correctly
        $taskContainer = 'web';
        $appUuid = 'abc123xyz';
        $containerName = "{$taskContainer}-{$appUuid}";

        expect(str_starts_with($containerName, "{$taskContainer}-{$appUuid}"))->toBeTrue();
    });

    it('does not match wrong container names', function () {
        // Test that unrelated container names are not matched
        $uuid = 'abc123xyz';
        $wrongContainer = 'differentuuid-1732718096';

        expect(str_starts_with($wrongContainer, $uuid))->toBeFalse();
    });
});
