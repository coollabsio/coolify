<?php

use App\Actions\V5\Flux\ApplyFluxResourceStatusUpdate;
use App\Events\V5CanvasResourceUpdated;
use App\Events\V5ClusterUpdated;
use App\Models\Team;
use App\Models\V5\Application as V5Application;
use App\Models\V5\Cluster;
use App\Models\V5\ContainerStatus;
use App\Models\V5\Server as V5Server;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    resetV5DashboardTestState();
});

it('applies flux application status updates to the database and broadcasts to the team canvas', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    [$project, $environment] = createV5ProjectWithEnvironment($team, 'Production Project', 'Production');
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'edge-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => [],
        'wireguard_management_ip' => '100.64.0.5',
    ]);
    $application = V5Application::query()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'created_by_user_id' => $user->id,
        'name' => 'nginx-test',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'coolify-v5-nginx-1',
        'status' => 'running',
        'status_message' => 'Container started.',
        'runtime_container_id' => 'nginx-container-id',
        'canvas_x' => 0,
        'canvas_y' => 0,
    ]);

    Event::fake([V5CanvasResourceUpdated::class]);

    $resource = ApplyFluxResourceStatusUpdate::run([
        'resource_type' => 'application',
        'host_id' => '100.64.0.5',
        'container_name' => 'coolify-v5-nginx-1',
        'container_id' => 'nginx-container-id',
        'status' => 'exited',
        'status_message' => 'Status received from coold through flux.',
    ]);

    expect($resource)->toBeInstanceOf(V5Application::class)
        ->and($application->refresh()->status)->toBe('exited')
        ->and($application->status_message)->toBe('Status received from coold through flux.')
        ->and($application->runtime_container_id)->toBe('nginx-container-id');

    Event::assertDispatched(V5CanvasResourceUpdated::class, fn (V5CanvasResourceUpdated $event) => $event->teamId === $team->id
        && $event->applicationId === $application->id);
});

it('ignores stale flux status updates for a superseded container', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    [$project, $environment] = createV5ProjectWithEnvironment($team, 'Production Project', 'Production');
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'edge-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => [],
        'wireguard_management_ip' => '100.64.0.5',
    ]);
    $application = V5Application::query()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'created_by_user_id' => $user->id,
        'name' => 'nginx-test',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'coolify-v5-nginx-1',
        'status' => 'running',
        'status_message' => 'Container started.',
        'runtime_container_id' => 'new-container-id',
    ]);

    Event::fake([V5CanvasResourceUpdated::class]);

    ApplyFluxResourceStatusUpdate::run([
        'resource_type' => 'application',
        'host_id' => '100.64.0.5',
        'container_name' => 'coolify-v5-nginx-1',
        'container_id' => 'old-container-id',
        'status' => 'exited',
        'status_message' => 'Stale event for the old container.',
    ]);

    expect($application->refresh()->status)->toBe('running')
        ->and($application->runtime_container_id)->toBe('new-container-id');

    Event::assertNotDispatched(V5CanvasResourceUpdated::class);
});

it('scopes flux application status updates to the reporting server', function () {
    createSharedUserAndTeamTables();

    [$userA, $teamA] = createV5UserWithTeam();
    [$userB, $teamB] = createV5UserWithTeam('other@example.com');
    [$projectA, $environmentA] = createV5ProjectWithEnvironment($teamA, 'Project A', 'Production');
    [$projectB, $environmentB] = createV5ProjectWithEnvironment($teamB, 'Project B', 'Production');
    $serverA = V5Server::query()->create([
        'team_id' => $teamA->id,
        'created_by_user_id' => $userA->id,
        'name' => 'edge-a',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => [],
        'wireguard_management_ip' => '100.64.0.5',
    ]);
    $serverB = V5Server::query()->create([
        'team_id' => $teamB->id,
        'created_by_user_id' => $userB->id,
        'name' => 'edge-b',
        'host' => '203.0.113.11',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => [],
        'wireguard_management_ip' => '100.64.0.6',
    ]);
    $applicationA = V5Application::query()->create([
        'team_id' => $teamA->id,
        'project_id' => $projectA->id,
        'environment_id' => $environmentA->id,
        'server_id' => $serverA->id,
        'created_by_user_id' => $userA->id,
        'name' => 'api',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'coolify-v5-api-a',
        'status' => 'running',
    ]);
    $applicationB = V5Application::query()->create([
        'uuid' => $applicationA->uuid.'-b',
        'team_id' => $teamB->id,
        'project_id' => $projectB->id,
        'environment_id' => $environmentB->id,
        'server_id' => $serverB->id,
        'created_by_user_id' => $userB->id,
        'name' => 'api',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'coolify-v5-api-b',
        'status' => 'running',
    ]);

    // An update reported by team A's host must never resolve to team B's app,
    // even when the payload carries team B's application uuid.
    $resource = ApplyFluxResourceStatusUpdate::run([
        'resource_type' => 'application',
        'host_id' => '100.64.0.5',
        'application_uuid' => $applicationB->uuid,
        'status' => 'exited',
    ]);

    expect($resource)->toBeNull()
        ->and($applicationA->refresh()->status)->toBe('running')
        ->and($applicationB->refresh()->status)->toBe('running');
});

it('ignores flux application status updates whose host does not resolve to a server', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    [$project, $environment] = createV5ProjectWithEnvironment($team, 'Production Project', 'Production');
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'edge-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => [],
        'wireguard_management_ip' => '100.64.0.5',
    ]);
    $application = V5Application::query()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'created_by_user_id' => $user->id,
        'name' => 'nginx-test',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'coolify-v5-nginx-1',
        'status' => 'running',
    ]);

    $resource = ApplyFluxResourceStatusUpdate::run([
        'resource_type' => 'application',
        'host_id' => '100.99.99.99',
        'application_uuid' => $application->uuid,
        'status' => 'exited',
    ]);

    expect($resource)->toBeNull()
        ->and($application->refresh()->status)->toBe('running');
});

it('maps generic flux container status updates to v5 applications', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    [$project, $environment] = createV5ProjectWithEnvironment($team, 'Production Project', 'Production');
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'edge-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => [],
        'wireguard_management_ip' => '100.64.0.5',
    ]);
    $application = V5Application::query()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'created_by_user_id' => $user->id,
        'name' => 'nginx-test',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'coolify-v5-nginx-1',
        'status' => 'running',
        'status_message' => 'Container started.',
        'runtime_container_id' => 'nginx-container-id',
    ]);

    Event::fake([V5CanvasResourceUpdated::class]);

    $resource = ApplyFluxResourceStatusUpdate::run([
        'resource_type' => 'container',
        'host_id' => '100.64.0.5',
        'container_name' => 'coolify-v5-nginx-1',
        'container_id' => 'nginx-container-id',
        'status' => 'exited',
        'status_message' => 'Container state received from coold.',
    ]);

    expect($resource)->toBeInstanceOf(V5Application::class)
        ->and($application->refresh()->status)->toBe('exited')
        ->and($application->status_message)->toBe('Container state received from coold.');

    Event::assertDispatched(V5CanvasResourceUpdated::class, fn (V5CanvasResourceUpdated $event) => $event->teamId === $team->id
        && $event->applicationId === $application->id);
});

it('applies flux ingress server status updates to the database and broadcasts cluster plus canvas updates', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Cluster',
    ]);
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'cluster_id' => $cluster->id,
        'created_by_user_id' => $user->id,
        'name' => 'edge-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => ['ingress'],
        'wireguard_management_ip' => '100.64.0.5',
    ]);

    Event::fake([V5CanvasResourceUpdated::class, V5ClusterUpdated::class]);

    $resource = ApplyFluxResourceStatusUpdate::run([
        'resource_type' => 'server',
        'host_id' => '100.64.0.5',
        'status' => 'unreachable',
        'message' => 'coold heartbeat timed out.',
    ]);

    expect($resource)->toBeInstanceOf(V5Server::class)
        ->and($server->refresh()->status)->toBe('unreachable')
        ->and($server->last_status_check)->toBe('flux')
        ->and($server->last_status_output)->toBe('coold heartbeat timed out.')
        ->and($server->last_status_checked_at)->not->toBeNull();

    Event::assertDispatched(V5ClusterUpdated::class, fn (V5ClusterUpdated $event) => $event->teamId === $team->id
        && $event->clusterId === $cluster->id);
    Event::assertDispatched(V5CanvasResourceUpdated::class, fn (V5CanvasResourceUpdated $event) => $event->teamId === $team->id
        && $event->caddyIngressServerId === $server->id);
});

it('ignores flux server status updates when a host id matches multiple v5 servers', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Cluster',
    ]);
    $serverOne = V5Server::query()->create([
        'team_id' => $team->id,
        'cluster_id' => $cluster->id,
        'created_by_user_id' => $user->id,
        'name' => 'edge-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'wireguard_management_ip' => '100.64.0.5',
    ]);
    $serverTwo = V5Server::query()->create([
        'team_id' => $team->id,
        'cluster_id' => $cluster->id,
        'created_by_user_id' => $user->id,
        'name' => 'edge-02',
        'host' => '203.0.113.11',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'wireguard_management_ip' => '100.64.0.5',
    ]);

    Event::fake([V5CanvasResourceUpdated::class, V5ClusterUpdated::class]);

    $resource = ApplyFluxResourceStatusUpdate::run([
        'resource_type' => 'server',
        'host_id' => '100.64.0.5',
        'status' => 'unreachable',
        'message' => 'ambiguous host id.',
    ]);

    expect($resource)->toBeNull()
        ->and($serverOne->refresh()->status)->toBe('installed')
        ->and($serverTwo->refresh()->status)->toBe('installed');

    Event::assertNotDispatched(V5ClusterUpdated::class);
    Event::assertNotDispatched(V5CanvasResourceUpdated::class);
});

it('broadcasts v5 canvas application updates when a non-ingress server goes unreachable', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    [$project, $environment] = createV5ProjectWithEnvironment($team, 'Production Project', 'Production');
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Cluster',
    ]);
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'cluster_id' => $cluster->id,
        'created_by_user_id' => $user->id,
        'name' => 'worker-01',
        'host' => '203.0.113.11',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => [],
        'wireguard_management_ip' => '100.64.0.6',
    ]);
    $application = V5Application::query()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'created_by_user_id' => $user->id,
        'name' => 'nginx-test',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'coolify-v5-nginx-1',
        'status' => 'running',
        'status_message' => 'Container started.',
    ]);

    Event::fake([V5CanvasResourceUpdated::class, V5ClusterUpdated::class]);

    $resource = ApplyFluxResourceStatusUpdate::run([
        'resource_type' => 'server',
        'host_id' => '100.64.0.6',
        'status' => 'unreachable',
        'message' => 'coold heartbeat timed out.',
    ]);

    expect($resource)->toBeInstanceOf(V5Server::class)
        ->and($server->refresh()->status)->toBe('unreachable');

    Event::assertDispatched(V5CanvasResourceUpdated::class, fn (V5CanvasResourceUpdated $event) => $event->teamId === $team->id
        && $event->serverId === $server->id);

    $payload = (new V5CanvasResourceUpdated($team->id, serverId: $server->id))->broadcastWith();
    expect($payload['applications'])
        ->toHaveCount(1)
        ->and($payload['applications'][0])
        ->toMatchArray([
            'id' => $application->uuid,
            'status' => 'running',
            'effectiveStatus' => 'unknown',
            'effectiveStatusMessage' => 'coold heartbeat timed out.',
            'serverStatus' => 'unreachable',
            'isServerReachable' => false,
        ]);
});

it('applies flux caddy ingress container status updates without changing server install status', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'edge-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'ingress_status' => 'running',
        'capabilities' => ['ingress'],
        'wireguard_management_ip' => '100.64.0.5',
    ]);

    Event::fake([V5CanvasResourceUpdated::class]);

    $resource = ApplyFluxResourceStatusUpdate::run([
        'resource_type' => 'caddy_ingress',
        'host_id' => '100.64.0.5',
        'container_name' => 'coolify-v5-caddy',
        'status' => 'exited',
        'message' => 'Caddy container exited.',
    ]);

    expect($resource)->toBeInstanceOf(V5Server::class)
        ->and($server->refresh()->status)->toBe('installed')
        ->and($server->ingress_type)->toBe('caddy')
        ->and($server->ingress_status)->toBe('exited')
        ->and($server->last_status_check)->toBe('flux')
        ->and($server->last_status_output)->toBe('Caddy container exited.')
        ->and($server->last_status_checked_at)->not->toBeNull();

    Event::assertDispatched(V5CanvasResourceUpdated::class, fn (V5CanvasResourceUpdated $event) => $event->teamId === $team->id
        && $event->caddyIngressServerId === $server->id);
});

it('accepts flux status updates for non coolify managed containers', function () {
    Config::set('flux.laravel_api_token', 'test-flux-token');
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    V5Server::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'edge-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'wireguard_management_ip' => '100.64.0.5',
    ]);

    $this
        ->postJson('/api/v1/internal/flux/resource-status', [
            'resource_type' => 'container',
            'host_id' => '100.64.0.5',
            'container_id' => 'external-container-id',
            'container_name' => 'external-container',
            'status' => 'running',
        ], [
            'Authorization' => 'Bearer test-flux-token',
        ])
        ->assertSuccessful()
        ->assertJsonPath('message', 'Resource status updated.');

    expect(ContainerStatus::query()
        ->where('container_id', 'external-container-id')
        ->where('container_name', 'external-container')
        ->where('status', 'running')
        ->exists())->toBeTrue();
});

it('rejects flux resource status http updates without the shared token', function () {
    Config::set('flux.laravel_api_token', 'test-flux-token');

    $this
        ->postJson('/api/v1/internal/flux/resource-status', [
            'resource_type' => 'application',
            'status' => 'running',
        ])
        ->assertUnauthorized();
});

it('accepts flux resource status http updates identified by resource uuids', function () {
    createSharedUserAndTeamTables();
    Config::set('flux.laravel_api_token', 'test-flux-token');

    [$user, $team] = createV5UserWithTeam();
    [$project, $environment] = createV5ProjectWithEnvironment($team, 'Production Project', 'Production');
    $server = V5Server::query()->create([
        'uuid' => 'server-public-uuid',
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'edge-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => [],
        'wireguard_management_ip' => '100.64.0.5',
    ]);
    $application = V5Application::query()->create([
        'uuid' => 'application-public-uuid',
        'team_id' => $team->id,
        'project_id' => $project->id,
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'created_by_user_id' => $user->id,
        'name' => 'nginx-test',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'coolify-v5-nginx-1',
        'status' => 'starting',
        'status_message' => 'Container starting.',
    ]);

    $this
        ->withToken('test-flux-token')
        ->postJson('/api/v1/internal/flux/resource-status', [
            'resource_type' => 'application',
            'server_uuid' => $server->uuid,
            'application_uuid' => $application->uuid,
            'container_id' => 'new-container-id',
            'status' => 'running',
            'status_message' => 'Status received from coold through flux.',
        ])
        ->assertSuccessful()
        ->assertJsonPath('message', 'Resource status updated.');

    expect($application->refresh()->status)->toBe('running')
        ->and($application->runtime_container_id)->toBe('new-container-id');
});

it('rejects numeric laravel ids in flux resource status http payloads', function () {
    Config::set('flux.laravel_api_token', 'test-flux-token');

    $this
        ->withToken('test-flux-token')
        ->postJson('/api/v1/internal/flux/resource-status', [
            'resource_type' => 'application',
            'server_id' => 1,
            'application_id' => 1,
            'status' => 'running',
        ])
        ->assertInvalid(['server_id', 'application_id']);
});

it('accepts flux resource status http updates and stores them in the database', function () {
    createSharedUserAndTeamTables();
    Config::set('flux.laravel_api_token', 'test-flux-token');

    [$user, $team] = createV5UserWithTeam();
    [$project, $environment] = createV5ProjectWithEnvironment($team, 'Production Project', 'Production');
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'edge-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => [],
        'wireguard_management_ip' => '100.64.0.5',
    ]);
    $application = V5Application::query()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'created_by_user_id' => $user->id,
        'name' => 'nginx-test',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'coolify-v5-nginx-1',
        'status' => 'starting',
        'status_message' => 'Container starting.',
        'runtime_container_id' => 'old-container-id',
    ]);

    Event::fake([V5CanvasResourceUpdated::class]);

    $this
        ->withToken('test-flux-token')
        ->postJson('/api/v1/internal/flux/resource-status', [
            'resource_type' => 'application',
            'host_id' => '100.64.0.5',
            'container_name' => 'coolify-v5-nginx-1',
            'container_id' => 'old-container-id',
            'status' => 'running',
            'status_message' => 'Status received from coold through flux.',
        ])
        ->assertSuccessful()
        ->assertJsonPath('message', 'Resource status updated.');

    expect($application->refresh()->status)->toBe('running')
        ->and($application->status_message)->toBe('Status received from coold through flux.')
        ->and($application->runtime_container_id)->toBe('old-container-id');

    Event::assertDispatched(V5CanvasResourceUpdated::class, fn (V5CanvasResourceUpdated $event) => $event->teamId === $team->id
        && $event->applicationId === $application->id);
});

it('configures flux resource status updates for local http instead of redis', function () {
    $configSource = file_get_contents(config_path('flux.php'));

    expect($configSource)
        ->toContain('COOLIFY_FLUX_LARAVEL_API_TOKEN')
        ->not->toContain('APP_KEY')
        ->not->toContain('COOLIFY_FLUX_RESOURCE_STATUS_CHANNEL')
        ->not->toContain('resource_status_channel');
});
