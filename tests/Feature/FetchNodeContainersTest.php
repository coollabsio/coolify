<?php

use App\Actions\Node\AssignNodeToCluster;
use App\Actions\Node\CreateNodeCluster;
use App\Actions\Node\FetchContainers;
use App\Enums\NodeContainerManagementState;
use App\Enums\NodeOperationStatus;
use App\Models\InstanceSettings;
use App\Models\Node;
use App\Models\NodeWorkload;
use App\Models\NodeWorkloadRevision;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('fetches, validates, and reconciles a complete container snapshot through Flux', function () {
    config()->set('constants.flux.internal_url', 'http://flux:7080');
    config()->set('constants.flux.internal_token', 'internal-secret');
    Http::fake([
        'http://flux:7080/v1/commands/container.list' => Http::response([
            'command_id' => 'command-1',
            'observed_at_unix_ms' => 1_789_237_260_000,
            'containers' => [[
                'runtime_id' => 'container-1',
                'name' => 'external-nginx',
                'image' => 'docker.io/library/nginx:latest',
                'state' => 'running',
                'health_status' => null,
                'restart_count' => 0,
                'ports' => [[
                    'host_ip' => '0.0.0.0',
                    'host_port' => 8080,
                    'container_port' => 80,
                    'protocol' => 'tcp',
                ]],
                'labels' => ['vendor' => 'example'],
                'created_at_unix_ms' => 1_789_237_060_000,
                'started_at_unix_ms' => 1_789_237_061_000,
            ]],
        ]),
    ]);
    $node = Node::factory()->create(['team_id' => Team::factory()]);

    $count = FetchContainers::run($node);

    expect($count)->toBe(1);
    $container = $node->containers()->firstOrFail();
    expect($container->name)->toBe('external-nginx')
        ->and($container->management_state)->toBe(NodeContainerManagementState::EXTERNAL)
        ->and($container->observed_at->timestamp)->toBe(1_789_237_260)
        ->and($container->runtime_created_at->timestamp)->toBe(1_789_237_060)
        ->and($container->ports[0]['host_port'])->toBe(8080)
        ->and(data_get($node->fresh()->metadata, 'container_inventory_observed_at'))->toBe('2026-09-12T18:21:00+00:00');
    Http::assertSent(fn ($request) => $request->url() === 'http://flux:7080/v1/commands/container.list'
        && $request->hasHeader('Authorization', 'Bearer internal-secret')
        && $request['server_id'] === $node->uuid);
});

it('does not replace stored inventory when Flux returns an invalid response', function () {
    config()->set('constants.flux.internal_url', 'http://flux:7080');
    config()->set('constants.flux.internal_token', 'internal-secret');
    Http::fake(['*' => Http::response(['containers' => [['runtime_id' => null]]])]);
    $node = Node::factory()->create(['team_id' => Team::factory()]);
    $node->containers()->create([
        'runtime_id' => 'existing',
        'name' => 'existing',
        'image' => 'alpine',
        'state' => 'running',
        'labels' => [],
        'management_state' => NodeContainerManagementState::EXTERNAL,
        'observed_at' => now(),
    ]);

    expect(fn () => FetchContainers::run($node))
        ->toThrow(RuntimeException::class, 'Flux returned invalid container inventory.')
        ->and($node->containers()->pluck('runtime_id')->all())->toBe(['existing']);
});

it('publishes an owned expiring discovery snapshot for managed cluster workloads', function () {
    InstanceSettings::forceCreate(['id' => 0, 'instance_uuid' => 'instance-test']);
    config()->set('app.env', 'local');
    config()->set('constants.sentinel.host_enabled', true);
    config()->set('constants.flux.internal_url', 'http://flux:7080');
    config()->set('constants.flux.internal_token', 'internal-secret');
    $user = User::factory()->create();
    $team = $user->teams()->firstOrFail();
    $cluster = CreateNodeCluster::run($team, $user, 'Discovery mesh');
    $node = Node::factory()->create(['team_id' => $team->id, 'name' => 'Worker Node A']);
    AssignNodeToCluster::run($cluster, $node);
    $cluster->update(['network_status' => 'active']);
    $workload = NodeWorkload::factory()->create(['team_id' => $team->id, 'name' => 'Example App']);
    $revision = NodeWorkloadRevision::factory()->create(['node_workload_id' => $workload->id]);
    $node->workloads()->attach($workload);

    Http::fake(function (Request $request) use ($node, $workload, $revision) {
        if (str_ends_with($request->url(), '/v1/commands/container.list')) {
            return Http::response([
                'command_id' => 'inventory-1',
                'observed_at_unix_ms' => 1_789_237_260_000,
                'containers' => [[
                    'runtime_id' => 'container-1',
                    'name' => 'coolify-'.$workload->uuid.'-main',
                    'image' => $revision->image,
                    'state' => 'running',
                    'health_status' => null,
                    'restart_count' => 0,
                    'ports' => [],
                    'labels' => [
                        'coolify.managed' => 'true',
                        'coolify.instance' => 'instance-test',
                        'coolify.workload' => $workload->uuid,
                        'coolify.revision' => $revision->uuid,
                        'coolify.component' => 'main',
                    ],
                ]],
            ]);
        }

        return Http::response([
            'command_id' => $request['command_id'],
            'observed_at_unix_ms' => 1_789_237_260_100,
            'owner_node_ip' => $node->wireguard_ip,
            'endpoint_count' => 2,
        ]);
    });

    FetchContainers::run($node->refresh());

    $operation = $node->operations()->where('command_type', 'discovery.corrosion.endpoints.reconcile.v1')->firstOrFail();
    expect($operation->status)->toBe(NodeOperationStatus::SUCCEEDED)
        ->and($operation->request['owner_node_ip'])->toBe($node->wireguard_ip)
        ->and($operation->request['endpoints'])->toBe([[
            'workload_id' => 'worker-node-a',
            'namespace' => 'nodes',
            'owner_node_ip' => $node->wireguard_ip,
            'container_ip' => $node->wireguard_ip,
            'state' => 'running',
            'health' => 'healthy',
            'updated_at_unix_seconds' => 1_789_237_260,
            'expires_at_unix_seconds' => 1_789_237_560,
        ], [
            'workload_id' => 'example-app',
            'namespace' => 'default',
            'owner_node_ip' => $node->wireguard_ip,
            'container_ip' => $node->wireguard_ip,
            'state' => 'running',
            'health' => 'unknown',
            'updated_at_unix_seconds' => 1_789_237_260,
            'expires_at_unix_seconds' => 1_789_237_560,
        ]]);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/v1/commands/discovery.corrosion.endpoints.reconcile')
        && $request['server_id'] === $node->uuid
        && $request['owner_node_ip'] === $node->wireguard_ip);
});
