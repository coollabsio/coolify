<?php

use App\Models\Service;

/**
 * Test suite for Service model's excluded status calculation.
 *
 * These tests verify the Service model's aggregateResourceStatuses() method
 * and getStatusAttribute() accessor, which aggregate status from applications
 * and databases. This is separate from the CalculatesExcludedStatus trait
 * because Service works with Eloquent model relationships (database records)
 * rather than Docker container objects.
 */

/**
 * Helper to create a mock resource (application or database) with status.
 */
function makeResource(string $status, bool $excludeFromStatus = false): object
{
    $resource = new stdClass;
    $resource->status = $status;
    $resource->exclude_from_status = $excludeFromStatus;

    return $resource;
}

describe('Service Excluded Status Calculation', function () {
    it('returns starting status when service is starting', function () {
        $service = Mockery::mock(Service::class)->makePartial();
        $service->shouldReceive('isStarting')->andReturn(true);
        $service->shouldReceive('getAttribute')->with('applications')->andReturn(collect());
        $service->shouldReceive('getAttribute')->with('databases')->andReturn(collect());

        expect($service->status)->toBe('starting:unhealthy');
    });

    it('aggregates status from non-excluded applications', function () {
        $service = Mockery::mock(Service::class)->makePartial();
        $service->shouldReceive('isStarting')->andReturn(false);

        $app1 = makeResource('running:healthy', excludeFromStatus: false);
        $app2 = makeResource('running:healthy', excludeFromStatus: false);

        $service->shouldReceive('getAttribute')->with('applications')->andReturn(collect([$app1, $app2]));
        $service->shouldReceive('getAttribute')->with('databases')->andReturn(collect());

        expect($service->status)->toBe('running:healthy');
    });

    it('returns excluded status when all containers are excluded', function () {
        $service = Mockery::mock(Service::class)->makePartial();
        $service->shouldReceive('isStarting')->andReturn(false);

        $app1 = makeResource('running:healthy', excludeFromStatus: true);

        $service->shouldReceive('getAttribute')->with('applications')->andReturn(collect([$app1]));
        $service->shouldReceive('getAttribute')->with('databases')->andReturn(collect());

        expect($service->status)->toBe('running:healthy:excluded');
    });

    it('returns unknown status when no containers exist', function () {
        $service = Mockery::mock(Service::class)->makePartial();
        $service->shouldReceive('isStarting')->andReturn(false);
        $service->shouldReceive('getAttribute')->with('applications')->andReturn(collect());
        $service->shouldReceive('getAttribute')->with('databases')->andReturn(collect());

        expect($service->status)->toBe('unknown:unknown:excluded');
    });

    it('handles mixed excluded and non-excluded containers', function () {
        $service = Mockery::mock(Service::class)->makePartial();
        $service->shouldReceive('isStarting')->andReturn(false);

        $app1 = makeResource('running:healthy', excludeFromStatus: false);
        $app2 = makeResource('exited', excludeFromStatus: true);

        $service->shouldReceive('getAttribute')->with('applications')->andReturn(collect([$app1, $app2]));
        $service->shouldReceive('getAttribute')->with('databases')->andReturn(collect());

        // Should only consider non-excluded containers
        expect($service->status)->toBe('running:healthy');
    });

    it('detects degraded status with mixed running and exited containers', function () {
        $service = Mockery::mock(Service::class)->makePartial();
        $service->shouldReceive('isStarting')->andReturn(false);

        $app1 = makeResource('running:healthy', excludeFromStatus: false);
        $app2 = makeResource('exited', excludeFromStatus: false);

        $service->shouldReceive('getAttribute')->with('applications')->andReturn(collect([$app1, $app2]));
        $service->shouldReceive('getAttribute')->with('databases')->andReturn(collect());

        expect($service->status)->toBe('degraded:unhealthy');
    });

    it('handles unknown health state', function () {
        $service = Mockery::mock(Service::class)->makePartial();
        $service->shouldReceive('isStarting')->andReturn(false);

        $app1 = makeResource('running:unknown', excludeFromStatus: false);

        $service->shouldReceive('getAttribute')->with('applications')->andReturn(collect([$app1]));
        $service->shouldReceive('getAttribute')->with('databases')->andReturn(collect());

        expect($service->status)->toBe('running:unknown');
    });

    it('prioritizes unhealthy over unknown health', function () {
        $service = Mockery::mock(Service::class)->makePartial();
        $service->shouldReceive('isStarting')->andReturn(false);

        $app1 = makeResource('running:unknown', excludeFromStatus: false);
        $app2 = makeResource('running:unhealthy', excludeFromStatus: false);

        $service->shouldReceive('getAttribute')->with('applications')->andReturn(collect([$app1, $app2]));
        $service->shouldReceive('getAttribute')->with('databases')->andReturn(collect());

        expect($service->status)->toBe('running:unhealthy');
    });

    it('prioritizes unknown over healthy health', function () {
        $service = Mockery::mock(Service::class)->makePartial();
        $service->shouldReceive('isStarting')->andReturn(false);

        $app1 = makeResource('running (healthy)', excludeFromStatus: false);
        $app2 = makeResource('running (unknown)', excludeFromStatus: false);

        $service->shouldReceive('getAttribute')->with('applications')->andReturn(collect([$app1, $app2]));
        $service->shouldReceive('getAttribute')->with('databases')->andReturn(collect());

        expect($service->status)->toBe('running:unknown');
    });

    it('handles restarting status as degraded', function () {
        $service = Mockery::mock(Service::class)->makePartial();
        $service->shouldReceive('isStarting')->andReturn(false);

        $app1 = makeResource('restarting:unhealthy', excludeFromStatus: false);

        $service->shouldReceive('getAttribute')->with('applications')->andReturn(collect([$app1]));
        $service->shouldReceive('getAttribute')->with('databases')->andReturn(collect());

        expect($service->status)->toBe('degraded:unhealthy');
    });

    it('handles paused status', function () {
        $service = Mockery::mock(Service::class)->makePartial();
        $service->shouldReceive('isStarting')->andReturn(false);

        $app1 = makeResource('paused:unknown', excludeFromStatus: false);

        $service->shouldReceive('getAttribute')->with('applications')->andReturn(collect([$app1]));
        $service->shouldReceive('getAttribute')->with('databases')->andReturn(collect());

        expect($service->status)->toBe('paused:unknown');
    });

    it('handles dead status as degraded', function () {
        $service = Mockery::mock(Service::class)->makePartial();
        $service->shouldReceive('isStarting')->andReturn(false);

        $app1 = makeResource('dead:unhealthy', excludeFromStatus: false);

        $service->shouldReceive('getAttribute')->with('applications')->andReturn(collect([$app1]));
        $service->shouldReceive('getAttribute')->with('databases')->andReturn(collect());

        expect($service->status)->toBe('degraded:unhealthy');
    });

    it('handles removing status as degraded', function () {
        $service = Mockery::mock(Service::class)->makePartial();
        $service->shouldReceive('isStarting')->andReturn(false);

        $app1 = makeResource('removing:unhealthy', excludeFromStatus: false);

        $service->shouldReceive('getAttribute')->with('applications')->andReturn(collect([$app1]));
        $service->shouldReceive('getAttribute')->with('databases')->andReturn(collect());

        expect($service->status)->toBe('degraded:unhealthy');
    });

    it('handles created status', function () {
        $service = Mockery::mock(Service::class)->makePartial();
        $service->shouldReceive('isStarting')->andReturn(false);

        $app1 = makeResource('created:unknown', excludeFromStatus: false);

        $service->shouldReceive('getAttribute')->with('applications')->andReturn(collect([$app1]));
        $service->shouldReceive('getAttribute')->with('databases')->andReturn(collect());

        expect($service->status)->toBe('starting:unknown');
    });

    it('aggregates status from both applications and databases', function () {
        $service = Mockery::mock(Service::class)->makePartial();
        $service->shouldReceive('isStarting')->andReturn(false);

        $app1 = makeResource('running:healthy', excludeFromStatus: false);
        $db1 = makeResource('running:healthy', excludeFromStatus: false);

        $service->shouldReceive('getAttribute')->with('applications')->andReturn(collect([$app1]));
        $service->shouldReceive('getAttribute')->with('databases')->andReturn(collect([$db1]));

        expect($service->status)->toBe('running:healthy');
    });

    it('detects unhealthy when database is unhealthy', function () {
        $service = Mockery::mock(Service::class)->makePartial();
        $service->shouldReceive('isStarting')->andReturn(false);

        $app1 = makeResource('running:healthy', excludeFromStatus: false);
        $db1 = makeResource('running:unhealthy', excludeFromStatus: false);

        $service->shouldReceive('getAttribute')->with('applications')->andReturn(collect([$app1]));
        $service->shouldReceive('getAttribute')->with('databases')->andReturn(collect([$db1]));

        expect($service->status)->toBe('running:unhealthy');
    });

    it('skips containers with :excluded suffix in non-excluded aggregation', function () {
        $service = Mockery::mock(Service::class)->makePartial();
        $service->shouldReceive('isStarting')->andReturn(false);

        $app1 = makeResource('running:healthy', excludeFromStatus: false);
        $app2 = makeResource('exited:excluded', excludeFromStatus: false);

        $service->shouldReceive('getAttribute')->with('applications')->andReturn(collect([$app1, $app2]));
        $service->shouldReceive('getAttribute')->with('databases')->andReturn(collect());

        // Should skip app2 because it has :excluded suffix
        expect($service->status)->toBe('running:healthy');
    });

    it('strips :excluded suffix when processing excluded containers', function () {
        $service = Mockery::mock(Service::class)->makePartial();
        $service->shouldReceive('isStarting')->andReturn(false);

        $app1 = makeResource('running:healthy:excluded', excludeFromStatus: true);

        $service->shouldReceive('getAttribute')->with('applications')->andReturn(collect([$app1]));
        $service->shouldReceive('getAttribute')->with('databases')->andReturn(collect());

        expect($service->status)->toBe('running:healthy:excluded');
    });

    it('returns exited when excluded containers have no valid status', function () {
        $service = Mockery::mock(Service::class)->makePartial();
        $service->shouldReceive('isStarting')->andReturn(false);

        $app1 = makeResource('', excludeFromStatus: true);

        $service->shouldReceive('getAttribute')->with('applications')->andReturn(collect([$app1]));
        $service->shouldReceive('getAttribute')->with('databases')->andReturn(collect());

        expect($service->status)->toBe('exited');
    });

    it('handles all excluded containers with degraded state', function () {
        $service = Mockery::mock(Service::class)->makePartial();
        $service->shouldReceive('isStarting')->andReturn(false);

        $app1 = makeResource('running:healthy', excludeFromStatus: true);
        $app2 = makeResource('exited', excludeFromStatus: true);

        $service->shouldReceive('getAttribute')->with('applications')->andReturn(collect([$app1, $app2]));
        $service->shouldReceive('getAttribute')->with('databases')->andReturn(collect());

        expect($service->status)->toBe('degraded:unhealthy:excluded');
    });

    it('handles all excluded containers with unknown health', function () {
        $service = Mockery::mock(Service::class)->makePartial();
        $service->shouldReceive('isStarting')->andReturn(false);

        $app1 = makeResource('running:unknown', excludeFromStatus: true);

        $service->shouldReceive('getAttribute')->with('applications')->andReturn(collect([$app1]));
        $service->shouldReceive('getAttribute')->with('databases')->andReturn(collect());

        expect($service->status)->toBe('running:unknown:excluded');
    });

    it('handles exited containers correctly', function () {
        $service = Mockery::mock(Service::class)->makePartial();
        $service->shouldReceive('isStarting')->andReturn(false);

        $app1 = makeResource('exited', excludeFromStatus: false);

        $service->shouldReceive('getAttribute')->with('applications')->andReturn(collect([$app1]));
        $service->shouldReceive('getAttribute')->with('databases')->andReturn(collect());

        expect($service->status)->toBe('exited');
    });

    it('prefers running over starting status', function () {
        $service = Mockery::mock(Service::class)->makePartial();
        $service->shouldReceive('isStarting')->andReturn(false);

        $app1 = makeResource('starting:unknown', excludeFromStatus: false);
        $app2 = makeResource('running:healthy', excludeFromStatus: false);

        $service->shouldReceive('getAttribute')->with('applications')->andReturn(collect([$app1, $app2]));
        $service->shouldReceive('getAttribute')->with('databases')->andReturn(collect());

        expect($service->status)->toBe('running:healthy');
    });

    it('treats empty health as healthy', function () {
        $service = Mockery::mock(Service::class)->makePartial();
        $service->shouldReceive('isStarting')->andReturn(false);

        $app1 = makeResource('running:', excludeFromStatus: false);

        $service->shouldReceive('getAttribute')->with('applications')->andReturn(collect([$app1]));
        $service->shouldReceive('getAttribute')->with('databases')->andReturn(collect());

        expect($service->status)->toBe('running:healthy');
    });
});
