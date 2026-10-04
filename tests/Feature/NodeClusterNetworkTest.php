<?php

use App\Actions\Node\AssignNodeToCluster;
use App\Actions\Node\CreateNodeCluster;
use App\Actions\Node\EnsureNodeWorkloadAddress;
use App\Actions\Node\ReconcileNodeClusterNetwork;
use App\Enums\NodeOperationStatus;
use App\Models\InstanceSettings;
use App\Models\Node;
use App\Models\NodeFirewallRule;
use App\Models\NodeIngressRule;
use App\Models\NodeOperation;
use App\Models\NodeWorkload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    config()->set('app.env', 'local');
    config()->set('constants.sentinel.host_enabled', true);
    config()->set('constants.flux.internal_url', 'http://flux.internal');
    config()->set('constants.flux.internal_token', 'internal-token');
    config()->set('constants.flux.public_url', 'https://192.0.2.1:7443');
    $this->user = User::factory()->create();
    $this->team = $this->user->teams()->firstOrFail();
});

function connectNetworkTestNode(Node $node, ?array $capabilities = null): void
{
    $node->update(['is_usable' => true]);
    Cache::put($node->cacheKey(), array_filter([
        'status' => 'connected',
        'last_heartbeat_at' => now()->toIso8601String(),
        'capabilities' => $capabilities,
    ], fn ($value) => $value !== null));
}

it('marks only the Node without a required Sentinel capability as failed', function () {
    $cluster = CreateNodeCluster::run($this->team, $this->user, 'Mesh');
    $node = Node::factory()->create(['team_id' => $this->team->id]);
    AssignNodeToCluster::run($cluster, $node);
    connectNetworkTestNode($node, ['network.wireguard.key.ensure.v1']);
    Http::fake();

    ReconcileNodeClusterNetwork::run($cluster->refresh(), $this->user);

    expect($node->refresh()->network_status)->toBe('error')
        ->and($node->network_error)->toContain('Upgrade Sentinel')
        ->and($cluster->refresh()->network_status)->toBe('error')
        ->and(NodeOperation::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('reconciles a complete full mesh through durable typed operations', function () {
    $cluster = CreateNodeCluster::run($this->team, $this->user, 'Mesh');
    $first = Node::factory()->create(['team_id' => $this->team->id, 'name' => 'Worker One', 'ip' => '192.0.2.10']);
    $second = Node::factory()->create(['team_id' => $this->team->id, 'name' => 'Worker Two', 'private_key_id' => $first->private_key_id, 'ip' => '192.0.2.11']);
    AssignNodeToCluster::run($cluster, $first);
    AssignNodeToCluster::run($cluster->refresh(), $second);
    connectNetworkTestNode($first);
    connectNetworkTestNode($second);
    $source = NodeWorkload::factory()->create(['team_id' => $this->team->id]);
    $destination = NodeWorkload::factory()->create(['team_id' => $this->team->id]);
    $sourceIp = EnsureNodeWorkloadAddress::run($first, $source);
    $destinationIp = EnsureNodeWorkloadAddress::run($second, $destination);
    NodeFirewallRule::factory()->create([
        'node_cluster_id' => $cluster->id,
        'source_workload_id' => $source->id,
        'destination_workload_id' => $destination->id,
        'protocol' => 'tcp',
        'port' => 5432,
    ]);
    NodeFirewallRule::factory()->create([
        'node_cluster_id' => $cluster->id,
        'source_workload_id' => null,
        'source_node_id' => $first->id,
        'destination_workload_id' => $destination->id,
        'protocol' => 'icmp',
        'port' => 0,
    ]);
    NodeIngressRule::factory()->create([
        'node_cluster_id' => $cluster->id,
        'destination_workload_id' => $destination->id,
        'protocol' => 'tcp',
        'port' => 8080,
    ]);

    $requests = collect();
    Http::fake(function (Request $request) use ($requests) {
        $requests->push(['url' => $request->url(), 'data' => $request->data()]);
        $data = $request->data();
        $base = ['command_id' => $data['command_id'], 'observed_at_unix_ms' => 1_700_000_000_000];

        return match (true) {
            str_ends_with($request->url(), 'network.wireguard.key.ensure') => Http::response([...$base, 'public_key' => 'public-'.$data['server_id']]),
            str_ends_with($request->url(), 'network.wireguard.reconcile') => Http::response([...$base, 'changed' => true, 'rollback_cancelled' => true, 'public_key' => 'public-'.$data['server_id'], 'listen_port' => 51820, 'applied_revision' => $data['revision'], 'configuration_hash' => 'wg-hash-'.$data['server_id'], 'drifted' => false, 'peers' => [['public_key' => 'peer', 'endpoint' => 'peer:51820', 'allowed_ips' => ['10.240.0.3/32'], 'latest_handshake_unix_seconds' => 1_700_000_000]]]),
            str_ends_with($request->url(), 'network.firewall.reconcile') => Http::response([...$base, 'changed' => true, 'rollback_cancelled' => true, 'applied_revision' => $data['revision'], 'configuration_hash' => 'firewall-hash', 'drifted' => false, 'table' => 'coolify_cluster', 'ingress_enforced' => true]),
            str_ends_with($request->url(), 'discovery.corrosion.reconcile') => Http::response([...$base, 'changed' => true, 'version' => 'v1.0.0', 'member_state' => 'joining', 'endpoint_count' => 2, 'last_convergence_unix_seconds' => null]),
            str_ends_with($request->url(), 'discovery.corrosion.inspect') => Http::response([...$base, 'version' => 'v1.0.0', 'member_state' => 'converged', 'endpoint_count' => 2, 'last_convergence_unix_seconds' => 1_700_000_000]),
            default => Http::response([], 404),
        };
    });

    ReconcileNodeClusterNetwork::run($cluster->refresh(), $this->user);

    expect($requests)->toHaveCount(10)
        ->and(NodeOperation::query()->count())->toBe(10)
        ->and(NodeOperation::query()->where('status', NodeOperationStatus::SUCCEEDED)->count())->toBe(10)
        ->and($cluster->refresh()->network_status)->toBe('active');
    foreach ([$first->refresh(), $second->refresh()] as $node) {
        expect($node->wireguard_public_key)->toBe('public-'.$node->uuid)
            ->and($node->network_applied_revision)->toBe($cluster->desired_revision)
            ->and($node->corrosion_status)->toBe('converged')
            ->and(data_get($node->metadata, 'firewall_applied_revision'))->toBe($cluster->desired_revision)
            ->and(data_get($node->metadata, 'firewall_configuration_hash'))->toBe('firewall-hash')
            ->and(data_get($node->metadata, 'corrosion_endpoint_count'))->toBe(2)
            ->and(data_get($node->metadata, 'corrosion_last_convergence_unix_seconds'))->toBe(1_700_000_000);
    }

    $wireguardRequests = $requests->filter(fn (array $request) => str_ends_with($request['url'], 'network.wireguard.reconcile'));
    expect($wireguardRequests)->toHaveCount(2);
    $wireguardRequests->each(function (array $request): void {
        expect($request['data']['address'])->toEndWith('/32')
            ->and($request['data']['peers'])->toHaveCount(1)
            ->and($request['data']['peers'][0]['allowed_ips'])->toHaveCount(2)
            ->and($request['data']['peers'][0]['allowed_ips'][0])->toEndWith('/32')
            ->and($request['data']['peers'][0]['allowed_ips'][1])->toEndWith('/24');
    });
    $corrosionRequests = $requests->filter(fn (array $request) => str_ends_with($request['url'], 'discovery.corrosion.reconcile'));
    expect($corrosionRequests->pluck('data.node_dns_name', 'data.server_id')->all())->toBe([
        $first->uuid => 'worker-one',
        $second->uuid => 'worker-two',
    ]);
    $corrosionOperation = NodeOperation::query()
        ->where('node_id', $first->id)
        ->where('command_type', 'discovery.corrosion.reconcile.v1')
        ->sole();
    expect($corrosionOperation->request['node_dns_name'])->toBe('worker-one');
    $firewallRequests = $requests->filter(fn (array $request) => str_ends_with($request['url'], 'network.firewall.reconcile'));
    $firewallRequests->each(fn (array $request) => expect($request['data']['flux_probe_host'])->toBe('192.0.2.1')
        ->and($request['data']['local_node_ip'])->toBeIn([$first->wireguard_ip, $second->wireguard_ip])
        ->and($request['data']['workload_cidrs'])->toHaveCount(2)
        ->and($request['data']['rules'])->toBe([
            [
                'source_ip' => $sourceIp,
                'destination_ip' => $destinationIp,
                'protocol' => 'tcp',
                'port' => 5432,
            ],
            [
                'source_ip' => $first->wireguard_ip,
                'destination_ip' => $destinationIp,
                'protocol' => 'icmp',
                'port' => 0,
            ],
        ])
        ->and($request['data']['ingress_rules'])->toBe([[
            'destination_ip' => $destinationIp,
            'protocol' => 'tcp',
            'port' => 8080,
        ]]));
});

it('marks the cluster unhealthy when a staged host change fails on its only Node', function () {
    $cluster = CreateNodeCluster::run($this->team, $this->user, 'Broken');
    $node = Node::factory()->create(['team_id' => $this->team->id]);
    AssignNodeToCluster::run($cluster, $node);
    connectNetworkTestNode($node);
    Http::fake(fn () => Http::response(['message' => 'failed'], 502));

    ReconcileNodeClusterNetwork::run($cluster->refresh(), $this->user);

    expect($cluster->refresh()->network_status)->toBe('error')
        ->and($node->refresh()->network_status)->toBe('error')
        ->and($node->network_error)->toContain('502')
        ->and($node->network_attempts)->toBe(1)
        ->and(NodeOperation::query()->where('status', NodeOperationStatus::FAILED)->exists())->toBeTrue();
});

it('rejects a network result when Sentinel did not cancel rollback', function () {
    $cluster = CreateNodeCluster::run($this->team, $this->user, 'Unsafe');
    $node = Node::factory()->create(['team_id' => $this->team->id]);
    AssignNodeToCluster::run($cluster, $node);
    connectNetworkTestNode($node);
    Http::fake(function (Request $request) {
        $data = $request->data();
        $base = ['command_id' => $data['command_id'], 'observed_at_unix_ms' => 1_700_000_000_000];

        return match (true) {
            str_ends_with($request->url(), 'network.wireguard.key.ensure') => Http::response([...$base, 'public_key' => 'public-key']),
            str_ends_with($request->url(), 'network.wireguard.reconcile') => Http::response([...$base, 'rollback_cancelled' => false, 'public_key' => 'public-key', 'listen_port' => 51820, 'applied_revision' => $data['revision'], 'configuration_hash' => 'hash', 'drifted' => false, 'peers' => []]),
            default => Http::response($base),
        };
    });

    ReconcileNodeClusterNetwork::run($cluster->refresh(), $this->user);

    expect($cluster->refresh()->network_status)->toBe('error')
        ->and($node->refresh()->network_status)->toBe('error')
        ->and($node->network_error)->toContain('unsafe');
});

it('rejects a firewall result that does not confirm ingress enforcement', function () {
    $cluster = CreateNodeCluster::run($this->team, $this->user, 'Old firewall agent');
    $node = Node::factory()->create(['team_id' => $this->team->id]);
    AssignNodeToCluster::run($cluster, $node);
    connectNetworkTestNode($node);
    Http::fake(function (Request $request) {
        $data = $request->data();
        $base = ['command_id' => $data['command_id'], 'observed_at_unix_ms' => 1_700_000_000_000];

        return match (true) {
            str_ends_with($request->url(), 'network.wireguard.key.ensure') => Http::response([...$base, 'public_key' => 'public-key']),
            str_ends_with($request->url(), 'network.wireguard.reconcile') => Http::response([...$base, 'rollback_cancelled' => true, 'public_key' => 'public-key', 'listen_port' => 51820, 'applied_revision' => $data['revision'], 'configuration_hash' => 'hash', 'drifted' => false, 'peers' => []]),
            str_ends_with($request->url(), 'network.firewall.reconcile') => Http::response([...$base, 'rollback_cancelled' => true, 'applied_revision' => $data['revision'], 'configuration_hash' => 'hash', 'drifted' => false, 'table' => 'coolify_cluster']),
            default => Http::response([], 404),
        };
    });

    ReconcileNodeClusterNetwork::run($cluster->refresh(), $this->user);

    expect($cluster->refresh()->network_status)->toBe('error')
        ->and($node->refresh()->network_status)->toBe('error')
        ->and($node->network_error)->toContain('unsafe')
        ->and(NodeOperation::query()->where('status', NodeOperationStatus::FAILED)->sole()->command_type)->toBe('network.firewall.reconcile.v1');
});

it('derives a valid Node discovery dns name', function (string $name, string $uuid, string $expected) {
    $node = new Node(['name' => $name, 'uuid' => $uuid]);

    expect($node->discoveryDnsName())->toBe($expected)
        ->and(strlen($node->discoveryDnsName()))->toBeLessThanOrEqual(63)
        ->and($node->discoveryDnsName())->toMatch('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/');
})->with([
    'slugged name' => ['Worker Node A', 'abc123', 'worker-node-a'],
    'empty slug falls back to the uuid' => ['!!!', 'Q8ZK2X7H4J', 'q8zk2x7h4j'],
    'blank name falls back to the uuid' => ['', 'node1uuid', 'node1uuid'],
    'non-ascii name is transliterated' => ['Nöde Ünïcode', 'abc123', 'node-unicode'],
    'long name is truncated to 63 characters' => [str_repeat('a', 70), 'abc123', str_repeat('a', 63)],
    'truncation does not leave a trailing dash' => [str_repeat('a', 62).' b', 'abc123', str_repeat('a', 62)],
]);
