<?php

use App\Actions\Service\DeleteService;
use App\Exceptions\EdgeProxyCleanupPendingException;
use App\Models\Service;
use App\Models\Server;
use App\Services\EdgeProxyRemotePortForwardService;
use App\Services\EdgeProxyRemoteRouteService;

it('keeps service pending deletion when edge route cleanup fails', function () {
    $server = Mockery::mock(Server::class)->makePartial();
    $server->id = 1;

    $service = Mockery::mock(Service::class)->makePartial();
    $service->uuid = 'service-cleanup-failure';
    $service->setRelation('server', $server);
    $service->setRelation('scheduled_tasks', collect());
    $service->shouldReceive('forceDelete')->never();

    $routeService = Mockery::mock(EdgeProxyRemoteRouteService::class);
    $routeService->shouldReceive('deleteService')->once()->with($service)->andReturn([
        'Failed to delete edge proxy route file for service service-cleanup-failure on edge server edge-1 (101): route cleanup failed',
    ]);

    $portForwardService = Mockery::mock(EdgeProxyRemotePortForwardService::class);
    $portForwardService->shouldReceive('deleteService')->once()->with($service)->andReturn([]);

    app()->instance(EdgeProxyRemoteRouteService::class, $routeService);
    app()->instance(EdgeProxyRemotePortForwardService::class, $portForwardService);

    $action = new class extends DeleteService
    {
        protected function runRemoteCommands(array $commands, $server, bool $throwError = true): ?string
        {
            return null;
        }
    };

    expect(fn () => $action->handle($service, false, false, false, false))
        ->toThrow(EdgeProxyCleanupPendingException::class, 'Edge cleanup pending for service service-cleanup-failure');
});

it('keeps service pending deletion when edge port cleanup fails', function () {
    $server = Mockery::mock(Server::class)->makePartial();
    $server->id = 3;

    $service = Mockery::mock(Service::class)->makePartial();
    $service->uuid = 'service-port-cleanup-failure';
    $service->setRelation('server', $server);
    $service->setRelation('scheduled_tasks', collect());
    $service->shouldReceive('forceDelete')->never();

    $routeService = Mockery::mock(EdgeProxyRemoteRouteService::class);
    $routeService->shouldReceive('deleteService')->once()->with($service)->andReturn([]);

    $portForwardService = Mockery::mock(EdgeProxyRemotePortForwardService::class);
    $portForwardService->shouldReceive('deleteService')->once()->with($service)->andReturn([
        'Failed to delete edge port proxy for service service-port-cleanup-failure on edge server edge-3 (303): port cleanup failed',
    ]);

    app()->instance(EdgeProxyRemoteRouteService::class, $routeService);
    app()->instance(EdgeProxyRemotePortForwardService::class, $portForwardService);

    $action = new class extends DeleteService
    {
        protected function runRemoteCommands(array $commands, $server, bool $throwError = true): ?string
        {
            return null;
        }
    };

    expect(fn () => $action->handle($service, false, false, false, false))
        ->toThrow(EdgeProxyCleanupPendingException::class, 'Edge cleanup pending for service service-port-cleanup-failure');
});

it('force deletes service after edge cleanup succeeds', function () {
    $server = Mockery::mock(Server::class)->makePartial();
    $server->id = 2;

    $service = Mockery::mock(Service::class)->makePartial();
    $service->uuid = 'service-cleanup-success';
    $service->setRelation('server', $server);
    $service->setRelation('scheduled_tasks', collect());
    $service->shouldReceive('applications->get')->once()->andReturn(collect());
    $service->shouldReceive('databases->get')->once()->andReturn(collect());
    $service->shouldReceive('tags->detach')->once();
    $service->shouldReceive('forceDelete')->once();

    $routeService = Mockery::mock(EdgeProxyRemoteRouteService::class);
    $routeService->shouldReceive('deleteService')->once()->with($service);

    $portForwardService = Mockery::mock(EdgeProxyRemotePortForwardService::class);
    $portForwardService->shouldReceive('deleteService')->once()->with($service);

    app()->instance(EdgeProxyRemoteRouteService::class, $routeService);
    app()->instance(EdgeProxyRemotePortForwardService::class, $portForwardService);

    $action = new class extends DeleteService
    {
        protected function runRemoteCommands(array $commands, $server, bool $throwError = true): ?string
        {
            return null;
        }
    };

    $action->handle($service, false, false, false, false);
});
