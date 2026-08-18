<?php

use App\Actions\V5\Flux\ApplyFluxResourceStatusUpdate;
use App\Events\V5CanvasResourceUpdated;
use App\Events\V5ClusterUpdated;
use App\Jobs\V5ReconcileServersJob;
use App\Jobs\V5ReconcileServerStateJob;
use App\Models\V5\Application as V5Application;
use App\Models\V5\ContainerStatus;
use App\Models\V5\Server as V5Server;
use App\Services\Flux\FluxClient;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\Support\V5TestSchema;

beforeEach(function () {
    Config::set('broadcasting.default', 'log');
    Config::set('cache.default', 'array');

    Schema::dropIfExists('v5_applications');
    Schema::dropIfExists('v5_container_statuses');
    Schema::dropIfExists('v5_servers');
});

function createReconcileV5Tables(): void
{
    V5TestSchema::createServersTable();
    V5TestSchema::createContainerStatusesTable();
    V5TestSchema::createApplicationsTable();
}

/**
 * @param  array<string, mixed>  $attributes
 */
function createReconcileServer(array $attributes = []): V5Server
{
    return V5Server::query()->create([
        'team_id' => 1,
        'created_by_user_id' => 1,
        'name' => 'edge-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => ['coold'],
        'wireguard_management_ip' => '100.64.0.10',
        ...$attributes,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function createReconcileApplication(V5Server $server, array $attributes = []): V5Application
{
    return V5Application::query()->create([
        'team_id' => $server->team_id,
        'project_id' => 1,
        'environment_id' => 1,
        'server_id' => $server->id,
        'created_by_user_id' => 1,
        'name' => 'nginx-test',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'coolify-v5-nginx-test',
        'status' => 'running',
        'runtime_container_id' => 'container-live',
        ...$attributes,
    ]);
}

it('marks a stale running application exited when its container is gone', function () {
    createReconcileV5Tables();
    Event::fake([V5CanvasResourceUpdated::class, V5ClusterUpdated::class]);

    $server = createReconcileServer();
    $application = createReconcileApplication($server);

    $fluxClient = Mockery::mock(FluxClient::class);
    $fluxClient->shouldReceive('listContainers')->once()->with($server->fluxHostId())->andReturn([]);

    (new V5ReconcileServerStateJob($server->id))->handle($fluxClient);

    expect($application->refresh())
        ->status->toBe('exited')
        ->status_message->toBe('Container not found on server during reconcile.');
    expect($server->refresh())
        ->status->toBe('installed')
        ->last_status_check->toBe('reconcile')
        ->last_status_checked_at->not->toBeNull();

    Event::assertDispatched(V5CanvasResourceUpdated::class, fn (V5CanvasResourceUpdated $event) => $event->applicationId === $application->id);
});

it('updates application status from the observed container state and refreshes container statuses', function () {
    createReconcileV5Tables();
    Event::fake([V5CanvasResourceUpdated::class, V5ClusterUpdated::class]);

    $server = createReconcileServer();
    $application = createReconcileApplication($server, ['runtime_container_id' => null]);

    $fluxClient = Mockery::mock(FluxClient::class);
    $fluxClient->shouldReceive('listContainers')->once()->andReturn([
        [
            'id' => 'container-live',
            'name' => 'coolify-v5-nginx-test',
            'image' => 'docker.io/library/nginx:alpine',
            'state' => 'Restarting',
        ],
    ]);

    (new V5ReconcileServerStateJob($server->id))->handle($fluxClient);

    expect($application->refresh())
        ->status->toBe('restarting')
        ->runtime_container_id->toBe('container-live');

    $containerStatus = ContainerStatus::query()
        ->where('server_id', $server->id)
        ->where('container_id', 'container-live')
        ->first();

    expect($containerStatus)->not->toBeNull()
        ->and($containerStatus->status)->toBe('restarting')
        ->and($containerStatus->last_seen_at)->not->toBeNull();
});

it('leaves a creating application without a container id alone during reconcile', function () {
    createReconcileV5Tables();
    Event::fake([V5CanvasResourceUpdated::class, V5ClusterUpdated::class]);

    $server = createReconcileServer();
    $application = createReconcileApplication($server, [
        'status' => 'creating',
        'runtime_container_id' => null,
    ]);

    $fluxClient = Mockery::mock(FluxClient::class);
    $fluxClient->shouldReceive('listContainers')->once()->andReturn([]);

    (new V5ReconcileServerStateJob($server->id))->handle($fluxClient);

    expect($application->refresh())->status->toBe('creating');
});

it('marks an installed server unreachable when the node cannot be reached', function () {
    createReconcileV5Tables();
    Event::fake([V5CanvasResourceUpdated::class, V5ClusterUpdated::class]);

    $server = createReconcileServer();
    $application = createReconcileApplication($server);

    $fluxClient = Mockery::mock(FluxClient::class);
    $fluxClient->shouldReceive('listContainers')->once()->andThrow(new RuntimeException('Flux did not return a response before the timeout.'));

    (new V5ReconcileServerStateJob($server->id))->handle($fluxClient);

    expect($server->refresh())
        ->status->toBe('unreachable')
        ->last_status_check->toBe('reconcile')
        ->last_status_output->toContain('Flux did not return a response');
    expect($application->refresh())->status->toBe('running');

    Event::assertDispatched(V5CanvasResourceUpdated::class, fn (V5CanvasResourceUpdated $event) => $event->serverId === $server->id);
});

it('does not mark a failed server unreachable when the node cannot be reached', function () {
    createReconcileV5Tables();
    Event::fake([V5CanvasResourceUpdated::class, V5ClusterUpdated::class]);

    $server = createReconcileServer(['status' => 'failed']);

    $fluxClient = Mockery::mock(FluxClient::class);
    $fluxClient->shouldReceive('listContainers')->once()->andThrow(new RuntimeException('boom'));

    (new V5ReconcileServerStateJob($server->id))->handle($fluxClient);

    expect($server->refresh())
        ->status->toBe('failed')
        ->last_status_check->toBe('reconcile');
});

it('restores an unreachable server to installed on a successful reconcile', function () {
    createReconcileV5Tables();
    Event::fake([V5CanvasResourceUpdated::class, V5ClusterUpdated::class]);

    $server = createReconcileServer(['status' => 'unreachable']);

    $fluxClient = Mockery::mock(FluxClient::class);
    $fluxClient->shouldReceive('listContainers')->once()->andReturn([]);

    (new V5ReconcileServerStateJob($server->id))->handle($fluxClient);

    expect($server->refresh())
        ->status->toBe('installed')
        ->last_status_check->toBe('reconcile');

    Event::assertDispatched(V5CanvasResourceUpdated::class, fn (V5CanvasResourceUpdated $event) => $event->serverId === $server->id);
});

it('dispatches per-server reconcile jobs only for managed servers', function () {
    createReconcileV5Tables();
    Queue::fake();

    $managed = createReconcileServer(['host' => '203.0.113.10', 'wireguard_management_ip' => '100.64.0.10']);
    $unreachable = createReconcileServer([
        'name' => 'edge-02',
        'host' => '203.0.113.11',
        'wireguard_management_ip' => '100.64.0.11',
        'status' => 'unreachable',
    ]);
    $withoutCoold = createReconcileServer([
        'name' => 'edge-03',
        'host' => '203.0.113.12',
        'wireguard_management_ip' => '100.64.0.12',
        'capabilities' => [],
    ]);
    $notInstalled = createReconcileServer([
        'name' => 'edge-04',
        'host' => '203.0.113.13',
        'wireguard_management_ip' => '100.64.0.13',
        'status' => 'added',
    ]);

    (new V5ReconcileServersJob)->handle();

    Queue::assertPushed(V5ReconcileServerStateJob::class, 2);
    Queue::assertPushed(V5ReconcileServerStateJob::class, fn (V5ReconcileServerStateJob $job) => $job->serverId === $managed->id);
    Queue::assertPushed(V5ReconcileServerStateJob::class, fn (V5ReconcileServerStateJob $job) => $job->serverId === $unreachable->id);
    Queue::assertNotPushed(V5ReconcileServerStateJob::class, fn (V5ReconcileServerStateJob $job) => $job->serverId === $withoutCoold->id);
    Queue::assertNotPushed(V5ReconcileServerStateJob::class, fn (V5ReconcileServerStateJob $job) => $job->serverId === $notInstalled->id);
});

it('drops a reconcile write when the stored observation is newer than the snapshot', function () {
    createReconcileV5Tables();
    Event::fake([V5CanvasResourceUpdated::class, V5ClusterUpdated::class]);

    $server = createReconcileServer();
    $application = createReconcileApplication($server, [
        'status' => 'running',
        'runtime_container_id' => 'container-live',
        // A fresher webhook already advanced the watermark past this pull.
        'status_observed_at' => now()->addMinutes(5),
    ]);

    $fluxClient = Mockery::mock(FluxClient::class);
    $fluxClient->shouldReceive('listContainers')->once()->andReturn([
        ['id' => 'container-live', 'name' => 'coolify-v5-nginx-test', 'state' => 'exited'],
    ]);

    (new V5ReconcileServerStateJob($server->id))->handle($fluxClient);

    expect($application->refresh())
        ->status->toBe('running')
        ->status_observed_at->not->toBeNull();

    Event::assertNotDispatched(V5CanvasResourceUpdated::class, fn (V5CanvasResourceUpdated $event) => $event->applicationId === $application->id);
});

it('advances status_observed_at when reconcile writes an application status', function () {
    createReconcileV5Tables();
    Event::fake([V5CanvasResourceUpdated::class, V5ClusterUpdated::class]);

    $server = createReconcileServer();
    $application = createReconcileApplication($server, [
        'status' => 'running',
        'runtime_container_id' => 'container-live',
        'status_observed_at' => null,
    ]);

    $fluxClient = Mockery::mock(FluxClient::class);
    $fluxClient->shouldReceive('listContainers')->once()->andReturn([
        ['id' => 'container-live', 'name' => 'coolify-v5-nginx-test', 'state' => 'restarting'],
    ]);

    (new V5ReconcileServerStateJob($server->id))->handle($fluxClient);

    expect($application->refresh())
        ->status->toBe('restarting')
        ->status_observed_at->not->toBeNull();
});

it('normalizes the coold configured state to a valid status on reconcile', function () {
    createReconcileV5Tables();
    Event::fake([V5CanvasResourceUpdated::class, V5ClusterUpdated::class]);

    $server = createReconcileServer();
    $application = createReconcileApplication($server);

    $fluxClient = Mockery::mock(FluxClient::class);
    $fluxClient->shouldReceive('listContainers')->once()->andReturn([
        ['id' => 'container-live', 'name' => 'coolify-v5-nginx-test', 'state' => 'configured'],
    ]);

    (new V5ReconcileServerStateJob($server->id))->handle($fluxClient);

    expect($application->refresh()->status)->toBe('configured');

    $containerStatus = ContainerStatus::query()
        ->where('server_id', $server->id)
        ->where('container_id', 'container-live')
        ->first();

    expect($containerStatus)->not->toBeNull()
        ->and($containerStatus->status)->toBe('configured');
});

it('normalizes the coold configured state on the flux webhook path', function () {
    createReconcileV5Tables();
    Event::fake([V5CanvasResourceUpdated::class, V5ClusterUpdated::class]);

    $server = createReconcileServer();
    $application = createReconcileApplication($server, ['runtime_container_id' => 'container-live']);

    ApplyFluxResourceStatusUpdate::run([
        'resource_type' => 'application',
        'server_uuid' => $server->uuid,
        'application_uuid' => $application->uuid,
        'status' => 'configured',
        'runtime_container_id' => 'container-live',
    ]);

    expect($application->refresh()->status)->toBe('configured');
});

it('does not broadcast when reconcile finds the application status unchanged', function () {
    createReconcileV5Tables();
    Event::fake([V5CanvasResourceUpdated::class, V5ClusterUpdated::class]);

    $server = createReconcileServer();
    $application = createReconcileApplication($server, [
        'status' => 'running',
        'runtime_container_id' => 'container-live',
    ]);

    $fluxClient = Mockery::mock(FluxClient::class);
    $fluxClient->shouldReceive('listContainers')->once()->andReturn([
        ['id' => 'container-live', 'name' => 'coolify-v5-nginx-test', 'state' => 'running'],
    ]);

    (new V5ReconcileServerStateJob($server->id))->handle($fluxClient);

    expect($application->refresh()->status)->toBe('running');

    Event::assertNotDispatched(V5CanvasResourceUpdated::class);
});

it('runs the reconcile jobs on the dedicated v5-reconcile queue', function () {
    expect((new V5ReconcileServersJob)->queue)->toBe('v5-reconcile')
        ->and((new V5ReconcileServerStateJob(1))->queue)->toBe('v5-reconcile');
});

it('prunes stale and orphaned container statuses but keeps fresh and live rows', function () {
    createReconcileV5Tables();
    Event::fake([V5CanvasResourceUpdated::class, V5ClusterUpdated::class]);
    Queue::fake();

    $server = createReconcileServer();
    createReconcileApplication($server, ['runtime_container_id' => 'container-live']);

    $staleRow = ContainerStatus::query()->create([
        'team_id' => 1,
        'server_id' => $server->id,
        'container_id' => 'container-stale',
        'status' => 'running',
        'last_seen_at' => now()->subHours(V5ReconcileServersJob::CONTAINER_STATUS_TTL_HOURS + 1),
    ]);
    $staleButLiveRow = ContainerStatus::query()->create([
        'team_id' => 1,
        'server_id' => $server->id,
        'container_id' => 'container-live',
        'status' => 'running',
        'last_seen_at' => now()->subHours(V5ReconcileServersJob::CONTAINER_STATUS_TTL_HOURS + 1),
    ]);
    $freshRow = ContainerStatus::query()->create([
        'team_id' => 1,
        'server_id' => $server->id,
        'container_id' => 'container-fresh',
        'status' => 'running',
        'last_seen_at' => now()->subHour(),
    ]);
    $orphanedServerRow = ContainerStatus::query()->create([
        'team_id' => 1,
        'server_id' => $server->id + 999,
        'container_id' => 'container-orphaned',
        'status' => 'running',
        'last_seen_at' => now()->subHour(),
    ]);

    (new V5ReconcileServersJob)->handle();

    expect(ContainerStatus::query()->find($staleRow->id))->toBeNull()
        ->and(ContainerStatus::query()->find($orphanedServerRow->id))->toBeNull()
        ->and(ContainerStatus::query()->find($staleButLiveRow->id))->not->toBeNull()
        ->and(ContainerStatus::query()->find($freshRow->id))->not->toBeNull();
});
