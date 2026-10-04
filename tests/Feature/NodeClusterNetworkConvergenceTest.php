<?php

use App\Actions\Node\AssignNodeToCluster;
use App\Actions\Node\CreateNodeCluster;
use App\Actions\Node\DeleteNodeCluster;
use App\Actions\Node\ReconcileNodeClusterNetwork;
use App\Actions\Node\RemoveNodeFromCluster;
use App\Jobs\InspectNodeClusterNetworksJob;
use App\Jobs\ReconcileNodeClusterNetworkJob;
use App\Livewire\NodeCluster\Show;
use App\Models\InstanceSettings;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function convergenceCapabilities(): array
{
    return [
        'network.wireguard.key.ensure.v1',
        'network.wireguard.reconcile.v1',
        'network.wireguard.inspect.v1',
        'network.firewall.reconcile.v1',
        'network.firewall.inspect.v1',
        'discovery.corrosion.inspect.v1',
        'discovery.corrosion.reconcile.v1',
        'network.cluster.leave.v1',
    ];
}

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    config()->set('app.env', 'local');
    config()->set('constants.sentinel.host_enabled', true);
    config()->set('constants.flux.internal_url', 'http://flux.internal');
    config()->set('constants.flux.internal_token', 'internal-token');
    config()->set('constants.flux.public_url', 'http://flux:7443');
    config()->set('constants.flux.development_allow_plaintext', true);
    Sleep::fake();
    $this->user = User::factory()->create();
    $this->team = $this->user->teams()->firstOrFail();
    $this->flux = new ArrayObject(['alive' => 0, 'failing' => [], 'drifted' => []]);
    $this->requests = fakeConvergenceFlux($this->flux);
});

/** @return Collection<int, array{command: string, server_id: string, data: array<string, mixed>}> */
function fakeConvergenceFlux(ArrayObject $state): Collection
{
    $requests = collect();
    Http::fake(function (Request $request) use ($state, $requests) {
        $data = $request->data();
        $command = Str::afterLast($request->url(), '/');
        $server = $data['server_id'];
        $requests->push(['command' => $command, 'server_id' => $server, 'data' => $data]);
        if (in_array($server, $state['failing'], true)) {
            return Http::response(['message' => 'Sentinel failed'], 502);
        }
        $base = ['command_id' => $data['command_id'], 'observed_at_unix_ms' => 1_700_000_000_000];

        return Http::response([...$base, ...match ($command) {
            'network.wireguard.key.ensure' => ['public_key' => 'public-'.$server],
            'network.wireguard.reconcile' => [
                'changed' => true,
                'rollback_cancelled' => true,
                'public_key' => 'public-'.$server,
                'listen_port' => $data['listen_port'],
                'applied_revision' => $data['revision'],
                'configuration_hash' => 'wg-'.$server,
                'drifted' => false,
                'peers' => $data['peers'],
            ],
            'network.firewall.reconcile' => [
                'changed' => true,
                'rollback_cancelled' => true,
                'applied_revision' => $data['revision'],
                'configuration_hash' => 'firewall-'.$server,
                'drifted' => false,
                'table' => 'coolify_cluster',
                'ingress_enforced' => true,
            ],
            'discovery.corrosion.reconcile' => ['changed' => true, 'version' => 'v1.0.0', 'member_state' => 'joining', 'endpoint_count' => 0],
            'discovery.corrosion.inspect' => ['version' => 'v1.0.0', 'member_state' => 'joining', 'endpoint_count' => 0, 'alive_member_count' => $state['alive']],
            'network.wireguard.inspect' => ['drifted' => in_array($server, $state['drifted'], true), 'applied_revision' => $data['expected_revision'], 'listen_port' => 51820],
            'network.firewall.inspect' => ['drifted' => false, 'applied_revision' => $data['expected_revision'], 'table' => 'coolify_cluster', 'ingress_enforced' => true],
            'network.cluster.leave' => ['wireguard_removed' => true, 'firewall_removed' => true, 'discovery_removed' => true, 'resolver_reverted' => true],
        }]);
    });

    return $requests;
}

function connectConvergenceNode(Node $node): void
{
    $node->update(['is_usable' => true]);
    Cache::put($node->cacheKey(), [
        'status' => 'connected',
        'last_heartbeat_at' => now()->toIso8601String(),
        'capabilities' => convergenceCapabilities(),
    ]);
}

function disconnectConvergenceNode(Node $node): void
{
    Cache::forget($node->cacheKey());
    $node->update(['is_reachable' => false]);
}

function reconnectConvergenceNode(Node $node): void
{
    test()->postJson('/api/v1/internal/sentinel/control/events', [
        'event' => 'connected',
        'server_id' => $node->uuid,
        'connection_id' => (string) Str::uuid(),
        'sentinel_version' => 'main',
        'protocol_version' => 1,
        'trust_bundle_version' => 1,
        'transport' => 'plaintext',
        'capabilities' => convergenceCapabilities(),
    ], ['Authorization' => 'Bearer internal-token'])->assertNoContent();
}

/**
 * A cluster whose Nodes all converged on the current desired revision.
 *
 * @return array{0: NodeCluster, 1: list<Node>}
 */
function convergedClusterWithNodes(User $user, int $count): array
{
    $team = $user->teams()->firstOrFail();
    $cluster = CreateNodeCluster::run($team, $user, 'Mesh '.Str::random(6));
    $nodes = [];
    for ($index = 1; $index <= $count; $index++) {
        $node = Node::factory()->create(array_filter([
            'team_id' => $team->id,
            'name' => "Worker {$index}",
            'private_key_id' => $nodes[0]->private_key_id ?? null,
        ]));
        AssignNodeToCluster::run($cluster->refresh(), $node);
        $nodes[] = $node;
    }
    $cluster->refresh()->update(['network_status' => 'active', 'last_reconciled_at' => now()]);
    foreach ($nodes as $node) {
        $node->update([
            'wireguard_public_key' => 'public-'.$node->uuid,
            'network_applied_revision' => $cluster->desired_revision,
            'network_status' => 'converged',
            'corrosion_status' => 'converged',
            'network_observed_state' => ['configuration_hash' => 'wg-'.$node->uuid],
            'metadata' => ['firewall_configuration_hash' => 'firewall-'.$node->uuid],
        ]);
        connectConvergenceNode($node);
    }

    return [$cluster->refresh(), array_map(fn (Node $node): Node => $node->refresh(), $nodes)];
}

function convergenceRequestsFor(Collection $requests, string $command, ?Node $node = null): Collection
{
    return $requests->filter(fn (array $request): bool => $request['command'] === $command
        && ($node === null || $request['server_id'] === $node->uuid))->values();
}

it('applies the network to reachable Nodes while an offline Node stays pending and remains a peer', function () {
    [$cluster, [$first, $second, $offline]] = convergedClusterWithNodes($this->user, 3);
    disconnectConvergenceNode($offline);
    $cluster->increment('desired_revision');
    $this->flux['alive'] = 1;

    ReconcileNodeClusterNetwork::run($cluster->refresh(), $this->user);

    $cluster->refresh();
    expect($cluster->network_status)->toBe('degraded')
        ->and($first->refresh()->network_status)->toBe('converged')
        ->and($first->network_applied_revision)->toBe($cluster->desired_revision)
        ->and($second->refresh()->network_status)->toBe('converged')
        ->and($offline->refresh()->network_status)->toBe('pending')
        ->and($cluster->nodeNetworkState($offline))->toBe('pending')
        ->and($this->requests->where('server_id', $offline->uuid))->toBeEmpty();

    $wireguard = convergenceRequestsFor($this->requests, 'network.wireguard.reconcile', $first)->sole();
    expect(collect($wireguard['data']['peers'])->pluck('public_key')->all())
        ->toContain('public-'.$offline->uuid)
        ->toContain('public-'.$second->uuid);
    $corrosion = convergenceRequestsFor($this->requests, 'discovery.corrosion.reconcile', $first)->sole();
    expect($corrosion['data']['peers'])->toContain($offline->wireguard_ip.':8787');
});

it('keeps converging the other Nodes when one Node fails a command', function () {
    [$cluster, [$first, $failing, $third]] = convergedClusterWithNodes($this->user, 3);
    $cluster->increment('desired_revision');
    $this->flux['failing'] = [$failing->uuid];
    $this->flux['alive'] = 1;

    ReconcileNodeClusterNetwork::run($cluster->refresh(), $this->user);

    expect($cluster->refresh()->network_status)->toBe('degraded')
        ->and($first->refresh()->network_status)->toBe('converged')
        ->and($third->refresh()->network_status)->toBe('converged')
        ->and($failing->refresh()->network_status)->toBe('error')
        ->and($failing->network_error)->toContain('502')
        ->and($failing->network_attempts)->toBe(1)
        ->and($failing->network_next_attempt_at->between(now()->addSeconds(59), now()->addSeconds(61)))->toBeTrue()
        ->and(convergenceRequestsFor($this->requests, 'network.firewall.reconcile', $failing))->toBeEmpty();
});

it('reconciles a pending Node as soon as it reconnects and then activates the cluster', function () {
    [$cluster, [$first, $second, $offline]] = convergedClusterWithNodes($this->user, 3);
    disconnectConvergenceNode($offline);
    $cluster->increment('desired_revision');
    $this->flux['alive'] = 1;
    ReconcileNodeClusterNetwork::run($cluster->refresh(), $this->user);
    expect($cluster->refresh()->network_status)->toBe('degraded');
    $this->requests->splice(0);
    $this->flux['alive'] = 2;

    reconnectConvergenceNode($offline);

    expect($offline->refresh()->network_status)->toBe('converged')
        ->and($offline->network_applied_revision)->toBe($cluster->refresh()->desired_revision)
        ->and($cluster->network_status)->toBe('active')
        ->and($this->requests->pluck('server_id')->unique()->values()->all())->toBe([$offline->uuid]);
});

it('queues only a Node-scoped reconciliation when a pending Node reconnects', function () {
    Queue::fake();
    [$cluster, [, $pending]] = convergedClusterWithNodes($this->user, 2);
    $pending->update(['network_status' => 'pending']);
    disconnectConvergenceNode($pending);

    reconnectConvergenceNode($pending);

    Queue::assertPushed(ReconcileNodeClusterNetworkJob::class, fn ($job) => $job->clusterId === $cluster->id
        && $job->nodeIds === [$pending->id]
        && $job->userId === $this->user->id);
});

it('does not queue a reconciliation when a converged Node reconnects', function () {
    Queue::fake();
    [, [$node]] = convergedClusterWithNodes($this->user, 2);

    reconnectConvergenceNode($node);

    Queue::assertNotPushed(ReconcileNodeClusterNetworkJob::class);
});

it('repairs a cluster in error once the retry of its failed Node is due', function () {
    [$cluster, [$healthy, $failed]] = convergedClusterWithNodes($this->user, 2);
    $revision = $cluster->desired_revision;
    $cluster->update(['network_status' => 'error']);
    $failed->update(['network_status' => 'error', 'network_error' => 'Old failure', 'network_attempts' => 3, 'network_next_attempt_at' => now()->subMinute()]);
    $this->flux['alive'] = 1;

    (new InspectNodeClusterNetworksJob)->handle();

    expect($cluster->refresh()->network_status)->toBe('active')
        ->and($cluster->desired_revision)->toBe($revision)
        ->and($failed->refresh()->network_status)->toBe('converged')
        ->and($failed->network_error)->toBeNull()
        ->and($failed->network_attempts)->toBe(0)
        ->and($failed->network_next_attempt_at)->toBeNull()
        ->and(convergenceRequestsFor($this->requests, 'network.wireguard.reconcile', $failed))->toHaveCount(1)
        ->and(convergenceRequestsFor($this->requests, 'network.wireguard.reconcile', $healthy))->toBeEmpty();
});

it('waits for the retry backoff of a failed Node', function () {
    [$cluster, [, $failed]] = convergedClusterWithNodes($this->user, 2);
    $failed->update(['network_status' => 'error', 'network_attempts' => 1, 'network_next_attempt_at' => now()->addMinute()]);
    $this->flux['alive'] = 1;

    (new InspectNodeClusterNetworksJob)->handle();

    expect($failed->refresh()->network_status)->toBe('error')
        ->and($this->requests->where('server_id', $failed->uuid))->toBeEmpty()
        ->and($cluster->refresh()->network_status)->toBe('degraded');
});

it('doubles the retry backoff after each failure up to thirty minutes', function (int $attempts, int $minutes) {
    [$cluster, [, $failed]] = convergedClusterWithNodes($this->user, 2);
    $failed->update(['network_status' => 'error', 'network_attempts' => $attempts, 'network_next_attempt_at' => now()->subSecond()]);
    $this->flux['failing'] = [$failed->uuid];
    $this->flux['alive'] = 1;

    (new InspectNodeClusterNetworksJob)->handle();

    expect($failed->refresh()->network_attempts)->toBe($attempts + 1)
        ->and($failed->network_next_attempt_at->between(now()->addMinutes($minutes)->subSecond(), now()->addMinutes($minutes)->addSecond()))->toBeTrue()
        ->and($cluster->refresh()->network_status)->toBe('degraded');
})->with([
    'second failure' => [1, 2],
    'third failure' => [2, 4],
    'capped' => [6, 30],
]);

it('re-applies the same revision only to a drifted Node', function () {
    [$cluster, [$drifted, $healthy]] = convergedClusterWithNodes($this->user, 2);
    $revision = $cluster->desired_revision;
    $this->flux['drifted'] = [$drifted->uuid];
    $this->flux['alive'] = 1;

    (new InspectNodeClusterNetworksJob)->handle();

    $wireguard = convergenceRequestsFor($this->requests, 'network.wireguard.reconcile');
    expect($cluster->refresh()->desired_revision)->toBe($revision)
        ->and($cluster->network_status)->toBe('active')
        ->and($wireguard->pluck('server_id')->all())->toBe([$drifted->uuid])
        ->and($wireguard->sole()['data']['revision'])->toBe($revision)
        ->and($drifted->refresh()->network_status)->toBe('converged')
        ->and($healthy->refresh()->network_status)->toBe('converged');
});

it('skips unreachable Nodes during automatic repair', function () {
    [$cluster, [, $offline]] = convergedClusterWithNodes($this->user, 2);
    $cluster->increment('desired_revision');
    $cluster->update(['network_status' => 'degraded']);
    $offline->update(['network_status' => 'pending']);
    disconnectConvergenceNode($offline);
    $this->flux['alive'] = 1;

    (new InspectNodeClusterNetworksJob)->handle();

    expect($this->requests->where('server_id', $offline->uuid))->toBeEmpty()
        ->and($offline->refresh()->network_status)->toBe('pending')
        ->and($cluster->refresh()->network_status)->toBe('degraded');
});

it('does not treat an offline peer as Corrosion drift', function () {
    [$cluster, [, , $offline]] = convergedClusterWithNodes($this->user, 3);
    disconnectConvergenceNode($offline);
    $this->flux['alive'] = 1;

    (new InspectNodeClusterNetworksJob)->handle();

    expect(convergenceRequestsFor($this->requests, 'discovery.corrosion.inspect'))->toHaveCount(2)
        ->and(convergenceRequestsFor($this->requests, 'network.wireguard.reconcile'))->toBeEmpty()
        ->and($cluster->refresh()->network_status)->toBe('active');
});

it('counts only reachable peers for Corrosion convergence', function (array $result, int $expectedPeers, bool $converged) {
    expect(ReconcileNodeClusterNetwork::corrosionConverged($result, $expectedPeers))->toBe($converged);
})->with([
    'every reachable peer alive' => [['version' => 'v1.0.0', 'member_state' => 'joining', 'alive_member_count' => 1], 1, true],
    'a reachable peer missing' => [['version' => 'v1.0.0', 'member_state' => 'joining', 'alive_member_count' => 1], 2, false],
    'single Node' => [['version' => 'v1.0.0', 'member_state' => 'joining', 'alive_member_count' => 0], 0, true],
    'inactive discovery' => [['version' => 'v1.0.0', 'member_state' => 'inactive', 'alive_member_count' => 3], 1, false],
    'unexpected version' => [['version' => 'v0.9.0', 'member_state' => 'converged', 'alive_member_count' => 3], 1, false],
    'older Sentinel converged' => [['version' => 'v1.0.0', 'member_state' => 'converged'], 5, true],
    'older Sentinel joining' => [['version' => 'v1.0.0', 'member_state' => 'joining'], 0, false],
]);

it('marks a Node failed when Corrosion does not see its reachable peers', function () {
    [$cluster, [$first, $second]] = convergedClusterWithNodes($this->user, 2);
    $cluster->increment('desired_revision');
    $this->flux['alive'] = 0;

    ReconcileNodeClusterNetwork::run($cluster->refresh(), $this->user);

    expect($first->refresh()->network_status)->toBe('error')
        ->and($first->network_error)->toContain('Corrosion did not converge')
        ->and($second->refresh()->network_status)->toBe('error')
        ->and($cluster->refresh()->network_status)->toBe('error');
});

it('removes an offline Node and sends its leave when it reconnects', function () {
    [$cluster, [$first, $second, $offline]] = convergedClusterWithNodes($this->user, 3);
    $offlineIp = $offline->wireguard_ip;
    disconnectConvergenceNode($offline);
    $this->flux['alive'] = 1;

    RemoveNodeFromCluster::run($cluster->refresh(), $offline->refresh(), $this->user);

    expect($offline->refresh()->node_cluster_id)->toBeNull()
        ->and($offline->network_pending_leave)->toMatchArray([
            'interface' => $cluster->wireguard_interface,
            'owner_node_ip' => $offlineIp,
        ])
        ->and($this->requests->where('server_id', $offline->uuid))->toBeEmpty()
        ->and($cluster->refresh()->network_status)->toBe('active');
    foreach ([$first, $second] as $survivor) {
        $wireguard = convergenceRequestsFor($this->requests, 'network.wireguard.reconcile', $survivor)->sole();
        expect(collect($wireguard['data']['peers'])->pluck('public_key')->all())->not->toContain('public-'.$offline->uuid);
        $corrosion = convergenceRequestsFor($this->requests, 'discovery.corrosion.reconcile', $survivor)->sole();
        expect($corrosion['data']['peers'])->not->toContain($offlineIp.':8787');
    }

    reconnectConvergenceNode($offline);

    $leave = convergenceRequestsFor($this->requests, 'network.cluster.leave', $offline)->sole();
    expect($leave['data']['interface'])->toBe($cluster->wireguard_interface)
        ->and($leave['data']['owner_node_ip'])->toBe($offlineIp)
        ->and($offline->refresh()->network_pending_leave)->toBeNull();
});

it('deletes a cluster with an offline Node and defers its leave', function () {
    [$cluster, [$online, $offline]] = convergedClusterWithNodes($this->user, 2);
    disconnectConvergenceNode($offline);

    DeleteNodeCluster::run($cluster->refresh(), $this->user);

    expect(NodeCluster::query()->whereKey($cluster->id)->exists())->toBeFalse()
        ->and($online->refresh()->network_pending_leave)->toBeNull()
        ->and($offline->refresh()->node_cluster_id)->toBeNull()
        ->and($offline->network_pending_leave)->not->toBeNull()
        ->and(convergenceRequestsFor($this->requests, 'network.cluster.leave')->pluck('server_id')->all())->toBe([$online->uuid]);
});

it('sends a deferred leave from the scheduled repair when the reconnect event was missed', function () {
    $node = Node::factory()->create(['team_id' => $this->team->id, 'network_pending_leave' => [
        'interface' => 'coolify0',
        'owner_node_ip' => '10.240.0.9',
        'workload_cidrs' => ['100.64.0.0/24'],
    ]]);
    connectConvergenceNode($node);

    (new InspectNodeClusterNetworksJob)->handle();

    expect(convergenceRequestsFor($this->requests, 'network.cluster.leave', $node))->toHaveCount(1)
        ->and($node->refresh()->network_pending_leave)->toBeNull();
});

it('keeps a deferred leave when the Node is still offline', function () {
    $node = Node::factory()->create(['team_id' => $this->team->id, 'is_usable' => true, 'network_pending_leave' => [
        'interface' => 'coolify0',
        'owner_node_ip' => '10.240.0.9',
        'workload_cidrs' => [],
    ]]);

    (new InspectNodeClusterNetworksJob)->handle();

    Http::assertNothingSent();
    expect($node->refresh()->network_pending_leave)->not->toBeNull();
});

it('runs one network reconciliation per cluster at a time', function () {
    [$cluster] = convergedClusterWithNodes($this->user, 2);
    $cluster->increment('desired_revision');
    $lock = $cluster->networkLock();
    expect($lock->get())->toBeTrue();

    expect(fn () => ReconcileNodeClusterNetwork::run($cluster->refresh(), $this->user))
        ->toThrow(LockTimeoutException::class);
    $job = (new ReconcileNodeClusterNetworkJob($cluster->id, $this->user->id))->withFakeQueueInteractions();
    $job->handle();
    $job->assertReleased(15);
    (new InspectNodeClusterNetworksJob)->handle();
    Http::assertNothingSent();

    $lock->release();
    $this->flux['alive'] = 1;
    ReconcileNodeClusterNetwork::run($cluster->refresh(), $this->user);

    expect($cluster->refresh()->network_status)->toBe('active');
});

it('prevents members from reconciling the network or removing Nodes', function () {
    [$cluster, [$node]] = convergedClusterWithNodes($this->user, 2);
    $member = User::factory()->create();
    $this->team->members()->attach($member, ['role' => 'member']);

    expect(fn () => ReconcileNodeClusterNetwork::run($cluster, $member))->toThrow(AuthorizationException::class)
        ->and(fn () => RemoveNodeFromCluster::run($cluster, $node, $member))->toThrow(AuthorizationException::class);

    $this->actingAs($member);
    session(['currentTeam' => $this->team]);
    Livewire::test(Show::class, ['cluster_uuid' => $cluster->uuid])
        ->call('removeNode', $node->uuid)
        ->assertForbidden();

    Http::assertNothingSent();
    expect($node->refresh()->node_cluster_id)->toBe($cluster->id);
});

it('prevents another team from reconciling the network or removing Nodes', function () {
    [$cluster, [$node]] = convergedClusterWithNodes($this->user, 2);
    $stranger = User::factory()->create();

    expect(fn () => ReconcileNodeClusterNetwork::run($cluster, $stranger))->toThrow(AuthorizationException::class)
        ->and(fn () => RemoveNodeFromCluster::run($cluster, $node, $stranger))->toThrow(AuthorizationException::class);

    Http::assertNothingSent();
    expect($node->refresh()->node_cluster_id)->toBe($cluster->id);
});

it('shows the network state and error of each cluster Node', function () {
    [$cluster, [, $failed]] = convergedClusterWithNodes($this->user, 2);
    $failed->update(['network_status' => 'error', 'network_error' => 'WireGuard refused the peer list.']);
    $cluster->update(['network_status' => 'degraded']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->get(route('node-cluster.nodes', $cluster->uuid))
        ->assertSuccessful()
        ->assertSee('Degraded')
        ->assertSee('In sync')
        ->assertSee('Failed')
        ->assertSee('WireGuard refused the peer list.');
});
