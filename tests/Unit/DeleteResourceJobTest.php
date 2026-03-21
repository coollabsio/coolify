<?php

use App\Jobs\DeleteResourceJob;
use App\Models\Application;
use App\Services\EdgeProxyRemotePortForwardService;
use App\Services\EdgeProxyRemoteRouteService;

it('does not force delete application when edge route cleanup fails', function () {
    $application = Mockery::mock(Application::class)->makePartial();
    $application->uuid = 'application-cleanup-failure';
    $application->shouldReceive('forceDelete')->never();

    $routeService = Mockery::mock(EdgeProxyRemoteRouteService::class);
    $routeService->shouldReceive('deleteApplication')->once()->with($application)->andThrow(new RuntimeException('application route cleanup failed'));

    $portForwardService = Mockery::mock(EdgeProxyRemotePortForwardService::class);
    $portForwardService->shouldReceive('deleteApplication')->never();

    app()->instance(EdgeProxyRemoteRouteService::class, $routeService);
    app()->instance(EdgeProxyRemotePortForwardService::class, $portForwardService);

    $job = new class($application, false, false, false, false) extends DeleteResourceJob
    {
        protected function prepareResourceForDeletion(): void {}

        protected function dispatchDockerCleanupIfNeeded(): void {}

        protected function queueStuckedResourcesCleanup(): void {}
    };

    expect(fn () => $job->handle())
        ->toThrow(RuntimeException::class, 'application route cleanup failed');
});

it('does not force delete application when edge port cleanup fails', function () {
    $application = Mockery::mock(Application::class)->makePartial();
    $application->uuid = 'application-port-cleanup-failure';
    $application->shouldReceive('forceDelete')->never();

    $routeService = Mockery::mock(EdgeProxyRemoteRouteService::class);
    $routeService->shouldReceive('deleteApplication')->once()->with($application);

    $portForwardService = Mockery::mock(EdgeProxyRemotePortForwardService::class);
    $portForwardService->shouldReceive('deleteApplication')->once()->with($application)->andThrow(new RuntimeException('application port cleanup failed'));

    app()->instance(EdgeProxyRemoteRouteService::class, $routeService);
    app()->instance(EdgeProxyRemotePortForwardService::class, $portForwardService);

    $job = new class($application, false, false, false, false) extends DeleteResourceJob
    {
        protected function prepareResourceForDeletion(): void {}

        protected function dispatchDockerCleanupIfNeeded(): void {}

        protected function queueStuckedResourcesCleanup(): void {}
    };

    expect(fn () => $job->handle())
        ->toThrow(RuntimeException::class, 'application port cleanup failed');
});

it('force deletes application after edge cleanup succeeds', function () {
    $application = Mockery::mock(Application::class)->makePartial();
    $application->uuid = 'application-cleanup-success';
    $application->shouldReceive('forceDelete')->once();

    $routeService = Mockery::mock(EdgeProxyRemoteRouteService::class);
    $routeService->shouldReceive('deleteApplication')->once()->with($application);

    $portForwardService = Mockery::mock(EdgeProxyRemotePortForwardService::class);
    $portForwardService->shouldReceive('deleteApplication')->once()->with($application);

    app()->instance(EdgeProxyRemoteRouteService::class, $routeService);
    app()->instance(EdgeProxyRemotePortForwardService::class, $portForwardService);

    $job = new class($application, false, false, false, false) extends DeleteResourceJob
    {
        protected function prepareResourceForDeletion(): void {}

        protected function dispatchDockerCleanupIfNeeded(): void {}

        protected function queueStuckedResourcesCleanup(): void {}
    };

    $job->handle();
});
