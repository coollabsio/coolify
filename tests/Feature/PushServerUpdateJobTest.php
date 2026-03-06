<?php

use App\Jobs\PushServerUpdateJob;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('containers with empty service subId are skipped', function () {
    $server = Server::factory()->create();
    $service = Service::factory()->create([
        'server_id' => $server->id,
    ]);
    $serviceApp = ServiceApplication::factory()->create([
        'service_id' => $service->id,
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

test('containers with valid service subId are processed', function () {
    $server = Server::factory()->create();
    $service = Service::factory()->create([
        'server_id' => $server->id,
    ]);
    $serviceApp = ServiceApplication::factory()->create([
        'service_id' => $service->id,
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
                    'coolify.service.subId' => (string) $serviceApp->id,
                    'com.docker.compose.service' => 'myapp',
                ],
            ],
        ],
    ];

    $job = new PushServerUpdateJob($server, $data);
    $job->handle();

    expect($job->foundServiceApplicationIds)->toContain((string) $serviceApp->id);
});
