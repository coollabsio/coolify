<?php

use App\Actions\Node\AssignNodeToCluster;
use App\Actions\Node\CreateNodeCluster;
use App\Actions\Node\EnsureNodeWorkloadDnsNames;
use App\Actions\Node\FetchContainers;
use App\Enums\NodeContainerManagementState;
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

it('does not publish discovery endpoints after a cluster inventory refresh', function () {
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

    Http::fake([
        'http://flux:7080/v1/commands/container.list' => Http::response([
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
                    'coolify.dns_name' => 'example-app',
                ],
            ]],
        ]),
        '*' => Http::response('unexpected request', 500),
    ]);

    FetchContainers::run($node->refresh());

    expect($node->containers()->sole()->management_state)->toBe(NodeContainerManagementState::MANAGED)
        ->and($node->operations()->where('command_type', 'discovery.corrosion.endpoints.reconcile.v1')->exists())->toBeFalse();
    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'discovery.corrosion.endpoints.reconcile'));
});

it('tracks workload ownership across a move without publishing discovery endpoints', function () {
    InstanceSettings::forceCreate(['id' => 0, 'instance_uuid' => 'instance-test']);
    config()->set('app.env', 'local');
    config()->set('constants.sentinel.host_enabled', true);
    config()->set('constants.flux.internal_url', 'http://flux:7080');
    config()->set('constants.flux.internal_token', 'internal-secret');
    $user = User::factory()->create();
    $team = $user->teams()->firstOrFail();
    $cluster = CreateNodeCluster::run($team, $user, 'Movement mesh');
    $nodeA = Node::factory()->create(['team_id' => $team->id, 'name' => 'Worker Node A']);
    $nodeB = Node::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $nodeA->private_key_id,
        'name' => 'Worker Node B',
    ]);
    AssignNodeToCluster::run($cluster, $nodeA);
    AssignNodeToCluster::run($cluster, $nodeB);
    $cluster->update(['network_status' => 'active']);
    $workload = NodeWorkload::factory()->create(['team_id' => $team->id, 'name' => 'Moving App']);
    $revision = NodeWorkloadRevision::factory()->create(['node_workload_id' => $workload->id]);
    $nodeA->workloads()->attach($workload);
    $inventoryCalls = 0;

    Http::fake(function (Request $request) use ($workload, $revision, &$inventoryCalls) {
        if (! str_ends_with($request->url(), '/v1/commands/container.list')) {
            return Http::response('unexpected request', 500);
        }
        $inventoryCalls++;

        return Http::response([
            'command_id' => 'inventory-'.$inventoryCalls,
            'observed_at_unix_ms' => 1_789_237_260_000 + ($inventoryCalls * 1000),
            'containers' => [[
                'runtime_id' => 'container-'.$request['server_id'],
                'name' => 'coolify-'.$workload->uuid.'-main',
                'image' => $revision->image,
                'state' => 'running',
                'health_status' => 'healthy',
                'restart_count' => 0,
                'ports' => [],
                'labels' => [
                    'coolify.managed' => 'true',
                    'coolify.instance' => 'instance-test',
                    'coolify.workload' => $workload->uuid,
                    'coolify.revision' => $revision->uuid,
                    'coolify.component' => 'main',
                    'coolify.dns_name' => 'moving-app',
                ],
            ]],
        ]);
    });

    FetchContainers::run($nodeA->refresh());
    $nodeA->workloads()->detach($workload);
    $nodeB->workloads()->attach($workload);
    FetchContainers::run($nodeA->refresh());
    FetchContainers::run($nodeB->refresh());

    expect($inventoryCalls)->toBe(3)
        ->and($nodeA->containers()->where('runtime_id', 'container-'.$nodeA->uuid)->firstOrFail()->management_state)
        ->toBe(NodeContainerManagementState::UNRECOGNIZED)
        ->and($nodeB->containers()->where('runtime_id', 'container-'.$nodeB->uuid)->firstOrFail()->management_state)
        ->toBe(NodeContainerManagementState::MANAGED);
    Http::assertSentCount(3);
});

it('keeps the first dns name permanent and suffixes only a later mesh collision', function () {
    $user = User::factory()->create();
    $team = $user->teams()->firstOrFail();
    $cluster = CreateNodeCluster::run($team, $user, 'Collision mesh');
    $nodeA = Node::factory()->create(['team_id' => $team->id, 'name' => 'Worker A']);
    $nodeB = Node::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $nodeA->private_key_id,
        'name' => 'Worker B',
    ]);
    AssignNodeToCluster::run($cluster, $nodeA);
    AssignNodeToCluster::run($cluster, $nodeB);
    $firstWorkload = NodeWorkload::factory()->create(['team_id' => $team->id, 'name' => 'My App']);
    $secondWorkload = NodeWorkload::factory()->create(['team_id' => $team->id, 'name' => 'my-app']);
    $nodeA->workloads()->attach($firstWorkload);
    EnsureNodeWorkloadDnsNames::run($nodeA->refresh());
    $nodeB->workloads()->attach($secondWorkload);
    $otherCluster = CreateNodeCluster::run($team, $user, 'Other mesh');
    $nodeC = Node::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $nodeA->private_key_id,
        'name' => 'Worker C',
    ]);
    AssignNodeToCluster::run($otherCluster, $nodeC);
    $otherMeshWorkload = NodeWorkload::factory()->create(['team_id' => $team->id, 'name' => 'My App']);
    $nodeC->workloads()->attach($otherMeshWorkload);

    EnsureNodeWorkloadDnsNames::run($nodeB->refresh());
    EnsureNodeWorkloadDnsNames::run($nodeC->refresh());

    $suffixedName = 'my-app-'.strtolower(substr($secondWorkload->uuid, 0, 8));
    expect($firstWorkload->refresh()->internal_dns_name)->toBe('my-app')
        ->and($secondWorkload->refresh()->internal_dns_name)->toBe($suffixedName)
        ->and($otherMeshWorkload->refresh()->internal_dns_name)->toBe('my-app');

    $firstWorkload->update(['name' => 'Renamed App']);

    expect(EnsureNodeWorkloadDnsNames::run($nodeA->refresh()))->toBe([
        $firstWorkload->id => 'my-app',
        $secondWorkload->id => $suffixedName,
    ]);
});
