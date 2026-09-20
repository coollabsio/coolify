<?php

use App\Actions\Node\AssignNodeToCluster;
use App\Actions\Node\CreateNodeCluster;
use App\Actions\Node\DeleteNodeCluster;
use App\Actions\Node\InspectNodeClusterDrift;
use App\Actions\Node\RemoveNodeFromCluster;
use App\Jobs\InspectNodeClusterNetworksJob;
use App\Jobs\ReconcileNodeClusterNetworkJob;
use App\Models\InstanceSettings;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\NodeWorkload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    config()->set('app.env', 'local');
    config()->set('constants.sentinel.host_enabled', true);
    config()->set('constants.flux.internal_url', 'http://flux.internal');
    config()->set('constants.flux.internal_token', 'internal-token');
    $this->user = User::factory()->create();
    $this->team = $this->user->teams()->firstOrFail();
});

function fakeNodeCleanup(): void
{
    Http::fake(function (Request $request) {
        $base = ['command_id' => $request['command_id'], 'observed_at_unix_ms' => 1_700_000_000_000];

        return str_ends_with($request->url(), 'discovery.corrosion.endpoints.reconcile')
            ? Http::response([...$base, 'owner_node_ip' => $request['owner_node_ip'], 'endpoint_count' => 0])
            : Http::response([...$base, 'wireguard_removed' => true, 'firewall_removed' => true, 'discovery_removed' => true, 'resolver_reverted' => true]);
    });
}

function grantCleanupCapabilities(Node $node): void
{
    Cache::put($node->cacheKey(), ['status' => 'connected', 'capabilities' => [
        'discovery.corrosion.endpoints.reconcile.v1',
        'network.cluster.leave.v1',
    ]]);
}

function grantInspectionCapabilities(Node $node): void
{
    Cache::put($node->cacheKey(), ['status' => 'connected', 'capabilities' => [
        'network.wireguard.inspect.v1',
        'network.firewall.inspect.v1',
        'discovery.corrosion.inspect.v1',
    ]]);
}

it('rejoins a removed Node without retaining its old network identity', function () {
    Queue::fake();
    $cluster = CreateNodeCluster::run($this->team, $this->user, 'Rejoin mesh');
    $node = Node::factory()->create(['team_id' => $this->team->id]);
    AssignNodeToCluster::run($cluster, $node);
    $oldAddress = $node->fresh()->wireguard_ip;
    $cluster->update(['network_status' => 'active', 'last_reconciled_at' => now()]);
    $node->update(['network_applied_revision' => $cluster->desired_revision, 'wireguard_public_key' => 'old-key']);
    grantCleanupCapabilities($node);
    fakeNodeCleanup();

    RemoveNodeFromCluster::run($cluster->fresh(), $node->fresh(), $this->user);
    AssignNodeToCluster::run($cluster->fresh(), $node->fresh(), $this->user);

    expect($node->fresh()->node_cluster_id)->toBe($cluster->id)
        ->and($node->fresh()->wireguard_ip)->toBe($oldAddress)
        ->and($node->fresh()->wireguard_public_key)->toBeNull()
        ->and($node->fresh()->network_applied_revision)->toBeNull()
        ->and($node->fresh()->network_observed_state)->toBeNull()
        ->and($cluster->fresh()->network_status)->toBe('reconciling')
        ->and($cluster->fresh()->desired_revision)->toBe(4);
    Queue::assertPushed(ReconcileNodeClusterNetworkJob::class, fn ($job) => $job->clusterId === $cluster->id);
});

it('detects healthy network state without requesting repair', function () {
    $cluster = CreateNodeCluster::run($this->team, $this->user, 'Healthy mesh');
    $node = Node::factory()->create(['team_id' => $this->team->id]);
    AssignNodeToCluster::run($cluster, $node);
    $cluster->refresh();
    $cluster->update(['network_status' => 'active']);
    $node->update([
        'network_applied_revision' => $cluster->desired_revision,
        'network_observed_state' => ['configuration_hash' => 'wg-hash'],
        'metadata' => ['firewall_configuration_hash' => 'firewall-hash'],
    ]);
    grantInspectionCapabilities($node);
    Http::fake(function (Request $request) use ($cluster) {
        $base = ['command_id' => $request['command_id'], 'observed_at_unix_ms' => 1_700_000_000_000];

        return match (true) {
            str_ends_with($request->url(), 'network.wireguard.inspect') => Http::response([...$base, 'drifted' => false, 'applied_revision' => $cluster->desired_revision, 'listen_port' => $cluster->wireguard_port]),
            str_ends_with($request->url(), 'network.firewall.inspect') => Http::response([...$base, 'drifted' => false, 'applied_revision' => $cluster->desired_revision, 'table' => 'coolify_cluster', 'ingress_enforced' => true]),
            default => Http::response([...$base, 'version' => 'v1.0.0', 'member_state' => 'converged']),
        };
    });

    expect(InspectNodeClusterDrift::run($cluster->fresh()))->toBeFalse();
    Http::assertSentCount(3);
});

it('queues automatic reconciliation when inspection detects drift', function () {
    Queue::fake();
    $cluster = CreateNodeCluster::run($this->team, $this->user, 'Drifted mesh');
    $node = Node::factory()->create(['team_id' => $this->team->id]);
    AssignNodeToCluster::run($cluster, $node);
    $cluster->update(['network_status' => 'active']);
    $node->update(['network_observed_state' => ['configuration_hash' => 'expected']]);
    grantInspectionCapabilities($node);
    Http::fake(fn (Request $request) => Http::response([
        'command_id' => $request['command_id'],
        'observed_at_unix_ms' => 1_700_000_000_000,
        'drifted' => true,
        'applied_revision' => 0,
        'listen_port' => 0,
    ]));

    (new InspectNodeClusterNetworksJob)->handle();

    expect($cluster->fresh()->network_status)->toBe('reconciling');
    Queue::assertPushed(ReconcileNodeClusterNetworkJob::class, fn ($job) => $job->clusterId === $cluster->id);
});

it('cleans every unused Node before deleting a cluster', function () {
    Queue::fake();
    $cluster = CreateNodeCluster::run($this->team, $this->user, 'Disposable mesh');
    $first = Node::factory()->create(['team_id' => $this->team->id]);
    $nodes = collect([
        $first,
        Node::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $first->private_key_id]),
    ]);
    foreach ($nodes as $node) {
        AssignNodeToCluster::run($cluster->fresh(), $node);
        grantCleanupCapabilities($node);
    }
    $cluster->refresh();
    $cluster->update(['network_status' => 'active']);
    Node::query()->whereKey($nodes->pluck('id'))->update(['network_applied_revision' => $cluster->desired_revision]);
    fakeNodeCleanup();

    DeleteNodeCluster::run($cluster->fresh(), $this->user);

    expect(NodeCluster::query()->whereKey($cluster->id)->exists())->toBeFalse();
    foreach ($nodes as $node) {
        expect($node->fresh()->node_cluster_id)->toBeNull()
            ->and($node->fresh()->wireguard_ip)->toBeNull();
    }
    Http::assertSentCount(4);
    Queue::assertNotPushed(ReconcileNodeClusterNetworkJob::class);
});

it('refuses cluster deletion while a workload is assigned', function () {
    $cluster = CreateNodeCluster::run($this->team, $this->user, 'Used mesh');
    $node = Node::factory()->create(['team_id' => $this->team->id]);
    AssignNodeToCluster::run($cluster, $node);
    $node->workloads()->attach(NodeWorkload::factory()->create(['team_id' => $this->team->id]));

    expect(fn () => DeleteNodeCluster::run($cluster->fresh(), $this->user))
        ->toThrow(DomainException::class, 'Remove all workloads');
    expect($cluster->fresh())->not->toBeNull()
        ->and($node->fresh()->node_cluster_id)->toBe($cluster->id);
    Http::assertNothingSent();
});
