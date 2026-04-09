<?php

use App\Exceptions\EdgeProxyCleanupPendingException;
use App\Jobs\DeleteResourceJob;
use App\Models\Application;
use App\Services\EdgeProxyRemotePortForwardService;
use App\Services\EdgeProxyRemoteRouteService;

it('keeps application pending deletion when edge route cleanup fails', function () {
    $application = Mockery::mock(Application::class)->makePartial();
    $application->uuid = 'application-cleanup-failure';
    $application->shouldReceive('trashed')->once()->andReturn(false);
    $application->shouldReceive('delete')->once();
    $application->shouldReceive('forceDelete')->never();

    $routeService = Mockery::mock(EdgeProxyRemoteRouteService::class);
    $routeService->shouldReceive('deleteApplication')->once()->with($application)->andReturn([
        'Failed to delete edge proxy route file for application application-cleanup-failure on edge server edge-1 (101): application route cleanup failed',
    ]);

    $portForwardService = Mockery::mock(EdgeProxyRemotePortForwardService::class);
    $portForwardService->shouldReceive('deleteApplication')->once()->with($application)->andReturn([]);

    app()->instance(EdgeProxyRemoteRouteService::class, $routeService);
    app()->instance(EdgeProxyRemotePortForwardService::class, $portForwardService);

    $job = new class($application, false, false, false, false) extends DeleteResourceJob
    {
        protected function prepareResourceForDeletion(): void {}

        protected function dispatchDockerCleanupIfNeeded(): void {}

        protected function queueStuckedResourcesCleanup(): void {}
    };

    expect(fn () => $job->handle())
        ->toThrow(EdgeProxyCleanupPendingException::class, 'Edge cleanup pending for application application-cleanup-failure');
});

it('keeps application pending deletion when edge port cleanup fails', function () {
    $application = Mockery::mock(Application::class)->makePartial();
    $application->uuid = 'application-port-cleanup-failure';
    $application->shouldReceive('trashed')->once()->andReturn(false);
    $application->shouldReceive('delete')->once();
    $application->shouldReceive('forceDelete')->never();

    $routeService = Mockery::mock(EdgeProxyRemoteRouteService::class);
    $routeService->shouldReceive('deleteApplication')->once()->with($application)->andReturn([]);

    $portForwardService = Mockery::mock(EdgeProxyRemotePortForwardService::class);
    $portForwardService->shouldReceive('deleteApplication')->once()->with($application)->andReturn([
        'Failed to delete edge port proxy for application application-port-cleanup-failure on edge server edge-2 (202): application port cleanup failed',
    ]);

    app()->instance(EdgeProxyRemoteRouteService::class, $routeService);
    app()->instance(EdgeProxyRemotePortForwardService::class, $portForwardService);

    $job = new class($application, false, false, false, false) extends DeleteResourceJob
    {
        protected function prepareResourceForDeletion(): void {}

        protected function dispatchDockerCleanupIfNeeded(): void {}

        protected function queueStuckedResourcesCleanup(): void {}
    };

    expect(fn () => $job->handle())
        ->toThrow(EdgeProxyCleanupPendingException::class, 'Edge cleanup pending for application application-port-cleanup-failure');
});

it('force deletes application after edge cleanup succeeds', function () {
    $application = Mockery::mock(Application::class)->makePartial();
    $application->uuid = 'application-cleanup-success';
    $application->shouldReceive('trashed')->once()->andReturn(false);
    $application->shouldReceive('delete')->once();
    $application->shouldReceive('forceDelete')->once();

    $routeService = Mockery::mock(EdgeProxyRemoteRouteService::class);
    $routeService->shouldReceive('deleteApplication')->once()->with($application)->andReturn([]);

    $portForwardService = Mockery::mock(EdgeProxyRemotePortForwardService::class);
    $portForwardService->shouldReceive('deleteApplication')->once()->with($application)->andReturn([]);

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

it('keeps application pending deletion when concrete edge port cleanup hits an ssh error', function () {
    $application = Mockery::mock(Application::class)->makePartial();
    $application->uuid = 'application-concrete-port-ssh-failure';
    $application->shouldReceive('trashed')->once()->andReturn(false);
    $application->shouldReceive('delete')->once();
    $application->shouldReceive('forceDelete')->never();
    $application->setRelation('environment', (object) [
        'project' => (object) ['team_id' => 402],
    ]);

    $routeService = new class extends EdgeProxyRemoteRouteService
    {
        protected function resolveEdgeProxyServersByTeamId(?int $teamId): \Illuminate\Support\Collection
        {
            return collect();
        }
    };

    $edgeProxyServer = Mockery::mock(\App\Models\Server::class)->makePartial();
    $edgeProxyServer->id = 402;
    $edgeProxyServer->name = 'edge-port-timeout';

    $portForwardService = new class($edgeProxyServer) extends EdgeProxyRemotePortForwardService
    {
        public function __construct(private \App\Models\Server $edgeProxyServer) {}

        protected function resolveEdgeProxyServersByTeamId(?int $teamId): \Illuminate\Support\Collection
        {
            return collect([$this->edgeProxyServer]);
        }

        protected function runRemoteCommands(\App\Models\Server $server, array $commands, bool $throwError = true): ?string
        {
            throw new RuntimeException('ssh: connect to host 10.10.10.11 port 22: Connection timed out');
        }
    };

    app()->instance(EdgeProxyRemoteRouteService::class, $routeService);
    app()->instance(EdgeProxyRemotePortForwardService::class, $portForwardService);

    $job = new class($application, false, false, false, false) extends DeleteResourceJob
    {
        protected function prepareResourceForDeletion(): void {}

        protected function dispatchDockerCleanupIfNeeded(): void {}

        protected function queueStuckedResourcesCleanup(): void {}
    };

    expect(fn () => $job->handle())
        ->toThrow(EdgeProxyCleanupPendingException::class, 'Edge cleanup pending for application application-concrete-port-ssh-failure');
});
