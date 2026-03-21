<?php

use App\Actions\Service\DeleteService;
use App\Models\Service;
use App\Models\Server;
use App\Services\EdgeProxyRemotePortForwardService;
use App\Services\EdgeProxyRemoteRouteService;

it('does not force delete service when edge route cleanup fails', function () {
    $server = Mockery::mock(Server::class)->makePartial();
    $server->id = 1;

    $service = Mockery::mock(Service::class)->makePartial();
    $service->uuid = 'service-cleanup-failure';
    $service->setRelation('server', $server);
    $service->shouldReceive('forceDelete')->never();

    $routeService = Mockery::mock(EdgeProxyRemoteRouteService::class);
    $routeService->shouldReceive('deleteService')->once()->with($service)->andThrow(new RuntimeException('route cleanup failed'));

    $portForwardService = Mockery::mock(EdgeProxyRemotePortForwardService::class);
    $portForwardService->shouldReceive('deleteService')->never();

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
        ->toThrow(RuntimeException::class, 'route cleanup failed');
});

it('does not force delete service when edge port cleanup fails', function () {
    $server = Mockery::mock(Server::class)->makePartial();
    $server->id = 3;

    $service = Mockery::mock(Service::class)->makePartial();
    $service->uuid = 'service-port-cleanup-failure';
    $service->setRelation('server', $server);
    $service->shouldReceive('forceDelete')->never();

    $routeService = Mockery::mock(EdgeProxyRemoteRouteService::class);
    $routeService->shouldReceive('deleteService')->once()->with($service);

    $portForwardService = Mockery::mock(EdgeProxyRemotePortForwardService::class);
    $portForwardService->shouldReceive('deleteService')->once()->with($service)->andThrow(new RuntimeException('port cleanup failed'));

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
        ->toThrow(RuntimeException::class, 'port cleanup failed');
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
