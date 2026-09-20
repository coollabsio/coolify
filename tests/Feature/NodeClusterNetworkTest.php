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
use Illuminate\Http\Client\RequestException;
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

it('rejects network reconciliation before changing state when Sentinel lacks a capability', function () {
    $cluster = CreateNodeCluster::run($this->team, $this->user, 'Mesh');
    $node = Node::factory()->create(['team_id' => $this->team->id]);
    AssignNodeToCluster::run($cluster, $node);
    Cache::put($node->cacheKey(), [
        'status' => 'connected',
        'capabilities' => ['network.wireguard.key.ensure.v1'],
    ]);

    expect(fn () => ReconcileNodeClusterNetwork::run($cluster->refresh(), $this->user))
        ->toThrow(RuntimeException::class, 'Upgrade Sentinel');

    expect($cluster->refresh()->network_status)->toBe('pending')
        ->and(NodeOperation::query()->count())->toBe(0);
});

it('reconciles a complete full mesh through durable typed operations', function () {
    $cluster = CreateNodeCluster::run($this->team, $this->user, 'Mesh');
    $first = Node::factory()->create(['team_id' => $this->team->id, 'ip' => '192.0.2.10']);
    $second = Node::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $first->private_key_id, 'ip' => '192.0.2.11']);
    AssignNodeToCluster::run($cluster, $first);
    AssignNodeToCluster::run($cluster->refresh(), $second);
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

it('marks the cluster unhealthy when a staged host change fails', function () {
    $cluster = CreateNodeCluster::run($this->team, $this->user, 'Broken');
    $node = Node::factory()->create(['team_id' => $this->team->id]);
    AssignNodeToCluster::run($cluster, $node);
    Http::fake(fn () => Http::response(['message' => 'failed'], 502));

    expect(fn () => ReconcileNodeClusterNetwork::run($cluster->refresh(), $this->user))->toThrow(RequestException::class);
    expect($cluster->refresh()->network_status)->toBe('error')
        ->and(NodeOperation::query()->where('status', NodeOperationStatus::FAILED)->exists())->toBeTrue();
});

it('rejects a network result when Sentinel did not cancel rollback', function () {
    $cluster = CreateNodeCluster::run($this->team, $this->user, 'Unsafe');
    $node = Node::factory()->create(['team_id' => $this->team->id]);
    AssignNodeToCluster::run($cluster, $node);
    Http::fake(function (Request $request) use ($cluster) {
        $data = $request->data();
        $base = ['command_id' => $data['command_id'], 'observed_at_unix_ms' => 1_700_000_000_000];

        return match (true) {
            str_ends_with($request->url(), 'network.wireguard.key.ensure') => Http::response([...$base, 'public_key' => 'public-key']),
            str_ends_with($request->url(), 'network.wireguard.reconcile') => Http::response([...$base, 'rollback_cancelled' => false, 'public_key' => 'public-key', 'listen_port' => 51820, 'applied_revision' => $cluster->desired_revision, 'configuration_hash' => 'hash', 'drifted' => false, 'peers' => []]),
            default => Http::response($base),
        };
    });

    expect(fn () => ReconcileNodeClusterNetwork::run($cluster->refresh(), $this->user))
        ->toThrow(RuntimeException::class, 'unsafe');
    expect($cluster->refresh()->network_status)->toBe('error');
});

it('rejects a firewall result that does not confirm ingress enforcement', function () {
    $cluster = CreateNodeCluster::run($this->team, $this->user, 'Old firewall agent');
    $node = Node::factory()->create(['team_id' => $this->team->id]);
    AssignNodeToCluster::run($cluster, $node);
    Http::fake(function (Request $request) use ($cluster) {
        $data = $request->data();
        $base = ['command_id' => $data['command_id'], 'observed_at_unix_ms' => 1_700_000_000_000];

        return match (true) {
            str_ends_with($request->url(), 'network.wireguard.key.ensure') => Http::response([...$base, 'public_key' => 'public-key']),
            str_ends_with($request->url(), 'network.wireguard.reconcile') => Http::response([...$base, 'rollback_cancelled' => true, 'public_key' => 'public-key', 'listen_port' => 51820, 'applied_revision' => $cluster->desired_revision, 'configuration_hash' => 'hash', 'drifted' => false, 'peers' => []]),
            str_ends_with($request->url(), 'network.firewall.reconcile') => Http::response([...$base, 'rollback_cancelled' => true, 'applied_revision' => $cluster->desired_revision, 'configuration_hash' => 'hash', 'drifted' => false, 'table' => 'coolify_cluster']),
            default => Http::response([], 404),
        };
    });

    expect(fn () => ReconcileNodeClusterNetwork::run($cluster->refresh(), $this->user))
        ->toThrow(RuntimeException::class, 'unsafe');
    expect($cluster->refresh()->network_status)->toBe('error');
});
