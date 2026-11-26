<?php

use App\Events\V5CanvasResourceUpdated;
use App\Events\V5ClusterUpdated;
use App\Models\V5\Application as V5Application;
use App\Models\V5\ApplicationDomain as V5ApplicationDomain;
use App\Models\V5\Cluster;
use App\Models\V5\Server as V5Server;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    resetV5DashboardTestState();
});

it('broadcasts v5 canvas resource updates when application state changes', function () {
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

    $application->update([
        'status' => 'exited',
        'status_message' => 'Container stopped.',
    ]);

    Event::assertDispatched(V5CanvasResourceUpdated::class, fn (V5CanvasResourceUpdated $event) => $event->teamId === $team->id
        && $event->applicationId === $application->id);
});

it('broadcasts the full v5 application canvas shape after application state changes', function () {
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
        'capabilities' => ['ingress'],
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
        'ingress_enabled' => true,
        'internal_port' => 80,
        'canvas_x' => 0,
        'canvas_y' => 0,
    ]);
    V5ApplicationDomain::query()->create([
        'application_id' => $application->id,
        'domain' => 'nginx.example.com',
    ]);

    $application->update([
        'status' => 'exited',
        'status_message' => 'Container stopped.',
    ]);

    $payload = (new V5CanvasResourceUpdated($team->id, $application->id))->broadcastWith();

    expect($payload['application'])
        ->toMatchArray([
            'id' => $application->uuid,
            'status' => 'exited',
            'statusMessage' => 'Container stopped.',
            'effectiveStatus' => 'exited',
            'effectiveStatusMessage' => 'Container stopped.',
            'serverName' => 'edge-01',
            'serverStatus' => 'installed',
            'isServerReachable' => true,
            'serverIngressEnabled' => true,
            'ingressEnabled' => true,
            'internalPort' => 80,
            'domains' => ['nginx.example.com'],
        ]);
});

it('broadcasts v5 application status as unknown when its server is unreachable', function () {
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
        'status' => 'unreachable',
        'last_status_output' => 'coold heartbeat timed out.',
        'capabilities' => ['ingress'],
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

    $payload = (new V5CanvasResourceUpdated($team->id, $application->id))->broadcastWith();

    expect($payload['application'])
        ->toMatchArray([
            'status' => 'running',
            'effectiveStatus' => 'unknown',
            'effectiveStatusMessage' => 'coold heartbeat timed out.',
            'serverStatus' => 'unreachable',
            'isServerReachable' => false,
        ]);
});

it('broadcasts v5 cluster and canvas updates when ingress server state changes', function () {
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
    ]);

    Event::fake([V5CanvasResourceUpdated::class, V5ClusterUpdated::class]);

    $server->update(['status' => 'unreachable']);

    Event::assertDispatched(V5ClusterUpdated::class, fn (V5ClusterUpdated $event) => $event->teamId === $team->id
        && $event->clusterId === $cluster->id);
    Event::assertDispatched(V5CanvasResourceUpdated::class, fn (V5CanvasResourceUpdated $event) => $event->teamId === $team->id
        && $event->caddyIngressServerId === $server->id);
});

it('broadcasts v5 cluster updates with uuid identifiers and the full server shape', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $cluster = Cluster::query()->create([
        'uuid' => 'cluster-public-uuid',
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Mesh',
        'description' => null,
    ]);
    $server = V5Server::query()->create([
        'uuid' => 'server-public-uuid',
        'team_id' => $team->id,
        'cluster_id' => $cluster->id,
        'created_by_user_id' => $user->id,
        'name' => 'edge-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => ['ingress'],
        'builder_enabled' => false,
        'builder_capacity' => 0,
        'ingress_type' => 'caddy',
        'last_status_output' => 'coold heartbeat ok.',
        'last_status_checked_at' => now(),
    ]);

    $payload = (new V5ClusterUpdated($team->id, $cluster->id))->broadcastWith();

    expect($payload['cluster']['id'])
        ->toBe($cluster->uuid)
        ->and($payload['cluster']['id'])->not->toBe((string) $cluster->id)
        ->and($payload['cluster']['servers'][0]['id'])->not->toBe((string) $server->id)
        ->and($payload['cluster']['servers'][0])->toMatchArray([
            'id' => $server->uuid,
            'uuid' => $server->uuid,
            'ingressEnabled' => true,
            'ingressType' => 'caddy',
            'lastStatusOutput' => 'coold heartbeat ok.',
        ])
        ->and($payload['cluster']['servers'][0])->toHaveKey('lastStatusCheckedAt');
});

it('resolves an empty v5 canvas broadcast payload when the models are deleted before the queued broadcast runs', function () {
    createSharedUserAndTeamTables();

    $payload = (new V5CanvasResourceUpdated(1, 999, 998, 997))->broadcastWith();

    expect($payload)->toBe([
        'application' => null,
        'applications' => [],
        'caddyIngress' => null,
    ]);
});

it('resolves a null v5 cluster broadcast payload when the cluster is deleted before the queued broadcast runs', function () {
    createSharedUserAndTeamTables();

    $payload = (new V5ClusterUpdated(1, 999))->broadcastWith();

    expect($payload)->toBe(['cluster' => null]);
});

it('broadcasts v5 canvas updates only after the database transaction commits', function () {
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
        'status' => 'creating',
    ]);

    Event::fake([V5CanvasResourceUpdated::class]);

    DB::transaction(function () use ($application): void {
        $application->update(['status' => 'running']);

        Event::assertNotDispatched(V5CanvasResourceUpdated::class);
    });

    Event::assertDispatched(V5CanvasResourceUpdated::class);
});
