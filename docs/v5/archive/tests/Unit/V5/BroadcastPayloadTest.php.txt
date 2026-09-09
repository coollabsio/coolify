<?php

use App\Events\V5CanvasResourceUpdated;
use App\Events\V5ClusterUpdated;
use App\Models\V5\Application as V5Application;
use App\Models\V5\Server as V5Server;
use App\Support\V5\CanvasResourceSerializer;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    resetV5DashboardTestState();
    createSharedUserAndTeamTables();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function createBroadcastPayloadServer(array $attributes = []): V5Server
{
    [$user, $team] = createV5UserWithTeam();

    return V5Server::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'edge-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'wireguard_management_ip' => '100.64.0.10',
        ...$attributes,
    ]);
}

it('serializes a null cluster payload when the cluster is gone before the queued broadcast runs', function () {
    [, $team] = createV5UserWithTeam();

    $event = new V5ClusterUpdated($team->id, 999);

    expect($event->broadcastWith())->toBe(['cluster' => null]);
});

it('omits the caddy ingress payload for a non-ingress server', function () {
    $server = createBroadcastPayloadServer(['is_ingress' => false]);

    $event = new V5CanvasResourceUpdated($server->team_id, null, $server->id);

    expect($event->broadcastWith()['caddyIngress'])->toBeNull();
});

it('serializes the caddy ingress payload for an ingress server', function () {
    $server = createBroadcastPayloadServer([
        'is_ingress' => true,
        'ingress_type' => 'caddy',
        'ingress_status' => 'running',
    ]);

    $payload = (new V5CanvasResourceUpdated($server->team_id, null, $server->id))->broadcastWith();

    expect($payload['caddyIngress'])->toBe(
        app(CanvasResourceSerializer::class)->serializeCaddyIngress($server->fresh()),
    )->and($payload['caddyIngress']['id'])->toBe($server->uuid);
});

it('broadcasts the same application shape as the initial-load canvas serializer', function () {
    $server = createBroadcastPayloadServer();
    $team = $server->team;
    [$project, $environment] = createV5ProjectWithEnvironment($team, 'Payload Parity', 'production');

    $application = V5Application::query()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'created_by_user_id' => $server->created_by_user_id,
        'name' => 'nginx-test',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'coolify-v5-nginx-parity',
        'status' => 'running',
        'status_message' => 'Container started.',
        'runtime_container_id' => 'container-123',
        'ingress_enabled' => true,
        'internal_port' => 8080,
    ]);
    $application->domains()->create(['domain' => 'parity.example.com']);

    $payload = (new V5CanvasResourceUpdated($team->id, $application->id))->broadcastWith();

    $expected = app(CanvasResourceSerializer::class)->serializeApplication(
        V5Application::query()->with(['server', 'domains'])->findOrFail($application->id),
    );

    expect($payload['application'])->toBe($expected)
        ->and($payload['application']['id'])->toBe($application->uuid)
        ->and($payload['application']['domains'])->toBe(['parity.example.com'])
        ->and($payload['application']['projectUuid'])->toBe($project->uuid)
        ->and($payload['application']['environmentUuid'])->toBe($environment->uuid);
});
