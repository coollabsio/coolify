<?php

use App\Jobs\PushServerUpdateJob;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('containers with empty service subId are skipped', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $service = Service::factory()->create([
        'server_id' => $server->id,
    ]);
    $serviceApp = ServiceApplication::create([
        'service_id' => $service->id,
        'uuid' => (string) str()->uuid(),
        'name' => 'app-'.str()->random(8),
    ]);

    $data = [
        'containers' => [
            [
                'name' => 'test-container',
                'state' => 'running',
                'health_status' => 'healthy',
                'labels' => [
                    'coolify.managed' => true,
                    'coolify.serviceId' => (string) $service->id,
                    'coolify.service.subType' => 'application',
                    'coolify.service.subId' => '',
                ],
            ],
        ],
    ];

    $job = new PushServerUpdateJob($server, $data);

    // Run handle - should not throw a PDOException about empty bigint
    $job->handle();

    // The empty subId container should have been skipped
    expect($job->foundServiceApplicationIds)->not->toContain('');
    expect($job->serviceContainerStatuses)->toBeEmpty();
});

test('service containers are matched by their UUID labels', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $service = Service::factory()->create([
        'server_id' => $server->id,
    ]);
    $serviceApp = ServiceApplication::create([
        'service_id' => $service->id,
        'uuid' => (string) str()->uuid(),
        'name' => 'app-'.str()->random(8),
    ]);

    $data = [
        'containers' => [
            [
                'name' => 'test-container',
                'state' => 'running',
                'health_status' => 'healthy',
                'labels' => [
                    'coolify.managed' => true,
                    'coolify.serviceUuid' => $service->uuid,
                    'coolify.service.subType' => 'application',
                    'coolify.service.subUuid' => $serviceApp->uuid,
                    'com.docker.compose.service' => 'myapp',
                ],
            ],
        ],
    ];

    $job = new PushServerUpdateJob($server, $data);
    $job->handle();

    expect($job->foundServiceApplicationIds)->toContain((string) $serviceApp->id);
});

test('legacy service containers from another instance are matched by compose project, not by their numeric ids', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $service = Service::factory()->create([
        'server_id' => $server->id,
    ]);
    $serviceApp = ServiceApplication::create([
        'service_id' => $service->id,
        'uuid' => (string) str()->uuid(),
        'name' => 'web',
    ]);

    // Ids from the source instance: they point to nothing, or to other resources, on this instance.
    $data = [
        'containers' => [
            [
                'name' => 'web-'.$service->uuid,
                'state' => 'running',
                'health_status' => 'healthy',
                'labels' => [
                    'coolify.managed' => true,
                    'coolify.serviceId' => (string) ($service->id + 1000),
                    'coolify.service.subType' => 'application',
                    'coolify.service.subId' => (string) ($serviceApp->id + 1000),
                    'com.docker.compose.project' => $service->uuid,
                    'com.docker.compose.service' => 'web',
                ],
            ],
        ],
    ];

    $job = new PushServerUpdateJob($server, $data);
    $job->handle();

    expect($job->foundServiceApplicationIds)->toContain((string) $serviceApp->id)
        ->and($job->serviceContainerStatuses->keys()->all())->toBe(["{$service->id}:application:{$serviceApp->id}"]);
});
