<?php

use App\Actions\Node\AssignNodeToCluster;
use App\Actions\Node\CreateNodeCluster;
use App\Actions\Node\EnsureNodeWorkloadAddress;
use App\Actions\Node\RemoveNodeFromCluster;
use App\Actions\Node\RepairNodeClusterNetwork;
use App\Actions\Node\UpdateNodeCluster;
use App\Enums\NodeOperationStatus;
use App\Jobs\ReconcileNodeClusterNetworkJob;
use App\Livewire\NodeCluster\Index;
use App\Livewire\NodeCluster\Show;
use App\Models\InstanceSettings;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\NodeFirewallRule;
use App\Models\NodeOperation;
use App\Models\NodeWorkload;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    config()->set('app.env', 'local');
    config()->set('constants.sentinel.host_enabled', true);
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    session(['currentTeam' => $this->user->teams()->firstOrFail()]);
});

it('allocates a different automatic private cidr for each cluster', function () {
    $team = $this->user->teams()->firstOrFail();
    $first = CreateNodeCluster::run($team, $this->user, 'First');
    $second = CreateNodeCluster::run($team, $this->user, 'Second');
    expect($first->cidr)->toBe('10.240.0.0/24')->and($second->cidr)->toBe('10.240.1.0/24');
});

it('stores cluster descriptions as text', function () {
    expect(Schema::getColumnType('node_clusters', 'description'))->toBe('text');
});

it('assigns one node with a stable private address', function () {
    $team = $this->user->teams()->firstOrFail();
    $cluster = CreateNodeCluster::run($team, $this->user, 'Production');
    $node = Node::factory()->create(['team_id' => $team->id]);
    AssignNodeToCluster::run($cluster, $node);
    expect($node->refresh()->node_cluster_id)->toBe($cluster->id)
        ->and($node->wireguard_ip)->toBe('10.240.0.2');
    AssignNodeToCluster::run($cluster, $node);
    expect($node->refresh()->wireguard_ip)->toBe('10.240.0.2');
});

it('allocates stable workload subnets and container addresses', function () {
    $team = $this->user->teams()->firstOrFail();
    $cluster = CreateNodeCluster::run($team, $this->user, 'Workload network');
    $first = Node::factory()->create(['team_id' => $team->id]);
    $second = Node::factory()->create(['team_id' => $team->id]);
    AssignNodeToCluster::run($cluster, $first);
    AssignNodeToCluster::run($cluster->refresh(), $second);
    $workload = NodeWorkload::factory()->create(['team_id' => $team->id]);

    $address = EnsureNodeWorkloadAddress::run($first, $workload);

    expect($first->refresh()->workload_cidr)->toBe('100.64.0.0/24')
        ->and($second->refresh()->workload_cidr)->toBe('100.64.1.0/24')
        ->and($address)->toBe('100.64.0.2')
        ->and(EnsureNodeWorkloadAddress::run($first, $workload))->toBe($address)
        ->and($first->workloads()->whereKey($workload->id)->firstOrFail()->pivot->container_ip)->toBe($address);
});

it('rejects cross-team membership', function () {
    $cluster = CreateNodeCluster::run($this->user->teams()->firstOrFail(), $this->user, 'Private');
    $foreign = Node::factory()->create();
    expect(fn () => AssignNodeToCluster::run($cluster, $foreign))->toThrow(DomainException::class);
});

it('requires explicit removal before assigning a node to another cluster', function () {
    $team = $this->user->teams()->firstOrFail();
    $first = CreateNodeCluster::run($team, $this->user, 'First');
    $second = CreateNodeCluster::run($team, $this->user, 'Second');
    $node = Node::factory()->create(['team_id' => $team->id]);
    AssignNodeToCluster::run($first, $node);

    expect(fn () => AssignNodeToCluster::run($second, $node->refresh()))
        ->toThrow(DomainException::class, 'already belongs');
});

it('shows only team clusters in the cluster UI', function () {
    $own = CreateNodeCluster::run($this->user->teams()->firstOrFail(), $this->user, 'Own cluster');
    NodeCluster::factory()->create(['name' => 'Foreign cluster']);
    Livewire::test(Index::class)
        ->assertSee($own->name)->assertDontSee('Foreign cluster');
});

it('renders cluster groups with the cluster actions on the servers page', function () {
    $view = file_get_contents(resource_path('views/livewire/node-cluster/index.blade.php'));

    expect($view)
        ->toContain('>Clusters</h2>')
        ->toContain('New cluster')
        ->toContain('Not in a cluster')
        ->toContain('Cluster settings')
        ->toContain('wire:key="cluster-group-{{ $cluster->uuid }}"')
        ->not->toContain('<x-slot:title>')
        ->not->toContain('title="Create cluster"');
});

it('validates and canonicalizes private cidrs', function (string $cidr) {
    $team = $this->user->teams()->firstOrFail();

    expect(fn () => CreateNodeCluster::run($team, $this->user, 'Invalid', cidr: $cidr))
        ->toThrow(DomainException::class);
})->with([
    'public range' => '8.8.8.0/24',
    'host bits' => '10.10.0.1/24',
    'ipv6' => 'fd00::/64',
    'too small for the node limit' => '10.10.0.0/26',
    'invalid prefix' => '10.10.0.0/33',
]);

it('rejects cidr overlap across all teams', function () {
    $team = $this->user->teams()->firstOrFail();
    CreateNodeCluster::run($team, $this->user, 'First', cidr: '10.20.0.0/16');

    $foreign = User::factory()->create();
    expect(fn () => CreateNodeCluster::run($foreign->teams()->firstOrFail(), $foreign, 'Overlap', cidr: '10.20.1.0/24'))
        ->toThrow(DomainException::class, 'overlaps');
});

it('assigns unique stable addresses and increments the desired revision', function () {
    $team = $this->user->teams()->firstOrFail();
    $cluster = CreateNodeCluster::run($team, $this->user, 'Network');
    $first = Node::factory()->create(['team_id' => $team->id]);
    $second = Node::factory()->create(['team_id' => $team->id]);

    AssignNodeToCluster::run($cluster, $first);
    AssignNodeToCluster::run($cluster->refresh(), $second);

    expect($first->refresh()->wireguard_ip)->toBe('10.240.0.2')
        ->and($second->refresh()->wireguard_ip)->toBe('10.240.0.3')
        ->and($cluster->refresh()->desired_revision)->toBe(3);
});

it('removes membership and increments the desired revision', function () {
    $team = $this->user->teams()->firstOrFail();
    $cluster = CreateNodeCluster::run($team, $this->user, 'Network');
    $node = Node::factory()->create(['team_id' => $team->id]);
    AssignNodeToCluster::run($cluster, $node);

    RemoveNodeFromCluster::run($cluster->refresh(), $node->refresh(), $this->user);

    expect($node->refresh()->node_cluster_id)->toBeNull()
        ->and($node->wireguard_ip)->toBeNull()
        ->and($cluster->refresh()->desired_revision)->toBe(3);
});

it('cleans an applied Node network before detaching it and reconciles survivors', function () {
    Queue::fake();
    config()->set('constants.flux.internal_url', 'http://flux:7080');
    config()->set('constants.flux.internal_token', 'secret');
    $team = $this->user->teams()->firstOrFail();
    $cluster = CreateNodeCluster::run($team, $this->user, 'Network');
    $leaving = Node::factory()->create(['team_id' => $team->id]);
    $survivor = Node::factory()->create(['team_id' => $team->id]);
    AssignNodeToCluster::run($cluster, $leaving);
    AssignNodeToCluster::run($cluster->refresh(), $survivor);
    $leavingIp = $leaving->fresh()->wireguard_ip;
    $cluster->update(['network_status' => 'active']);
    $leaving->update(['network_applied_revision' => $cluster->desired_revision]);
    $workload = NodeWorkload::factory()->create(['team_id' => $team->id]);
    $leaving->workloads()->attach($workload, ['container_ip' => '100.64.0.2']);
    $leaving->update(['is_usable' => true]);
    Cache::put($leaving->cacheKey(), ['status' => 'connected', 'last_heartbeat_at' => now()->toIso8601String(), 'capabilities' => [
        'network.cluster.leave.v1',
    ]]);
    Http::fake(fn ($request) => Http::response([
        'command_id' => $request['command_id'],
        'observed_at_unix_ms' => 1_700_000_000_000,
        'wireguard_removed' => true,
        'firewall_removed' => true,
        'discovery_removed' => true,
        'resolver_reverted' => true,
    ]));

    RemoveNodeFromCluster::run($cluster->fresh(), $leaving->fresh(), $this->user);

    expect($leaving->fresh()->node_cluster_id)->toBeNull()
        ->and($leaving->fresh()->workload_cidr)->toBeNull()
        ->and($leaving->workloads()->count())->toBe(0)
        ->and($cluster->fresh()->network_status)->toBe('reconciling');
    Http::assertSentCount(1);
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'discovery.corrosion.endpoints.reconcile'));
    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), 'network.cluster.leave')
        && $request['interface'] === $cluster->wireguard_interface
        && $request['owner_node_ip'] === $leavingIp);
    Queue::assertPushed(ReconcileNodeClusterNetworkJob::class, fn ($job) => $job->clusterId === $cluster->id);
});

it('keeps membership when remote Node cleanup fails', function () {
    config()->set('constants.flux.internal_url', 'http://flux:7080');
    config()->set('constants.flux.internal_token', 'secret');
    $team = $this->user->teams()->firstOrFail();
    $cluster = CreateNodeCluster::run($team, $this->user, 'Network');
    $node = Node::factory()->create(['team_id' => $team->id]);
    AssignNodeToCluster::run($cluster, $node);
    $cluster->update(['network_status' => 'active']);
    $node->update(['network_applied_revision' => $cluster->desired_revision]);
    $node->update(['is_usable' => true]);
    Cache::put($node->cacheKey(), ['status' => 'connected', 'last_heartbeat_at' => now()->toIso8601String(), 'capabilities' => ['network.cluster.leave.v1']]);
    Http::fake(['*' => Http::response('failed', 502)]);

    expect(fn () => RemoveNodeFromCluster::run($cluster->fresh(), $node->fresh(), $this->user))->toThrow(RequestException::class)
        ->and($node->fresh()->node_cluster_id)->toBe($cluster->id);
});

it('limits a cluster to one hundred nodes', function () {
    $team = $this->user->teams()->firstOrFail();
    $cluster = CreateNodeCluster::run($team, $this->user, 'Full');
    Node::factory()->count(100)->create(['team_id' => $team->id, 'node_cluster_id' => $cluster->id]);

    expect(fn () => AssignNodeToCluster::run($cluster, Node::factory()->create(['team_id' => $team->id])))
        ->toThrow(DomainException::class, 'at most 100');
});

it('uses a real detail component and denies cross-team routes', function () {
    $cluster = CreateNodeCluster::run($this->user->teams()->firstOrFail(), $this->user, 'Own');
    $foreign = NodeCluster::factory()->create();

    $this->get(route('node-cluster.show', $cluster->uuid))->assertSuccessful()->assertSeeLivewire(Show::class);
    $this->get(route('node-cluster.show', $foreign->uuid))->assertNotFound();
});

it('prevents members from mutating clusters', function () {
    $team = $this->user->teams()->firstOrFail();
    $cluster = CreateNodeCluster::run($team, $this->user, 'Protected');
    $team->members()->updateExistingPivot($this->user->id, ['role' => 'member']);

    Livewire::test(Show::class, ['cluster_uuid' => $cluster->uuid])
        ->call('saveSettings')
        ->assertForbidden();

    Livewire::test(Show::class, ['cluster_uuid' => $cluster->uuid])
        ->call('deleteCluster', 'password')
        ->assertForbidden();
});

it('prevents members from changing firewall rules', function () {
    $team = $this->user->teams()->firstOrFail();
    $cluster = CreateNodeCluster::run($team, $this->user, 'Protected firewall');
    $team->members()->updateExistingPivot($this->user->id, ['role' => 'member']);

    Livewire::test(Show::class, ['cluster_uuid' => $cluster->uuid])
        ->call('addFirewallRule')
        ->assertForbidden();

    Livewire::test(Show::class, ['cluster_uuid' => $cluster->uuid])
        ->call('createFirewallRule', 'workload', 'source', 'destination', 'tcp', 80)
        ->assertForbidden();
});

it('does not allow active cidr changes', function () {
    $cluster = CreateNodeCluster::run($this->user->teams()->firstOrFail(), $this->user, 'Active');
    $cluster->update(['network_status' => 'active']);

    Livewire::test(Show::class, ['cluster_uuid' => $cluster->uuid])
        ->set('cidr', '10.30.0.0/24')
        ->call('saveSettings')
        ->assertHasErrors('cidr');

    expect(fn () => UpdateNodeCluster::run(
        $cluster->refresh(),
        $cluster->name,
        $cluster->description,
        '10.30.0.0/24',
        $cluster->wireguard_interface,
        $cluster->wireguard_port,
    ))->toThrow(DomainException::class, 'cannot be changed');
});

it('does not allow cidr changes after an active network enters an error state', function () {
    $cluster = CreateNodeCluster::run($this->user->teams()->firstOrFail(), $this->user, 'Previously active');
    $cluster->update(['network_status' => 'error', 'last_reconciled_at' => now()]);

    Livewire::test(Show::class, ['cluster_uuid' => $cluster->uuid])
        ->set('cidr', '10.30.0.0/24')
        ->call('saveSettings')
        ->assertHasErrors('cidr');
});

it('deletes a cluster after cleaning unused assigned nodes', function () {
    $team = $this->user->teams()->firstOrFail();
    $cluster = CreateNodeCluster::run($team, $this->user, 'Used');
    $node = Node::factory()->create(['team_id' => $team->id]);
    AssignNodeToCluster::run($cluster, $node);

    Livewire::test(Show::class, ['cluster_uuid' => $cluster->uuid])
        ->call('deleteCluster', 'password')
        ->assertRedirect(route('server.index'));

    expect($cluster->fresh())->toBeNull()
        ->and($node->fresh()->node_cluster_id)->toBeNull();
});

it('creates edits assigns removes and deletes through Livewire', function () {
    $team = $this->user->teams()->firstOrFail();
    Livewire::test(Index::class)->set('name', 'UI cluster')->call('createCluster')->assertDispatched('closeModal')->assertDispatched('success');
    $cluster = NodeCluster::query()->where('team_id', $team->id)->sole();
    $node = Node::factory()->create(['team_id' => $team->id]);

    Livewire::test(Show::class, ['cluster_uuid' => $cluster->uuid])
        ->set('description', 'Updated')
        ->call('saveSettings')->assertDispatched('success')
        ->set('nodeUuid', $node->uuid)
        ->call('assignNode')->assertDispatched('success')
        ->call('removeNode', $node->uuid)->assertDispatched('success')
        ->call('deleteCluster', 'password');

    expect(NodeCluster::query()->whereKey($cluster->id)->exists())->toBeFalse();
});

it('does not expose foreign nodes to assignment actions', function () {
    $cluster = CreateNodeCluster::run($this->user->teams()->firstOrFail(), $this->user, 'Own');
    $foreign = Node::factory()->create();

    expect(fn () => Livewire::test(Show::class, ['cluster_uuid' => $cluster->uuid])
        ->set('nodeUuid', $foreign->uuid)
        ->call('assignNode'))
        ->toThrow(ModelNotFoundException::class);
});

it('shows the system-managed core cluster firewall rules', function () {
    $cluster = CreateNodeCluster::run($this->user->teams()->firstOrFail(), $this->user, 'Core rules mesh');

    $this->get(route('node-cluster.firewall', $cluster->uuid))
        ->assertSuccessful()
        ->assertSee('System rules')
        ->assertSee('Managed by Coolify')
        ->assertSee('WireGuard')
        ->assertSee('UDP / 51820')
        ->assertSee('Corrosion gossip')
        ->assertSee('UDP / 8787')
        ->assertSee('Corrosion local API')
        ->assertSee('TCP / 8080')
        ->assertSee('Application DNS')
        ->assertSee('TCP + UDP / 53')
        ->assertSee('Established connections')
        ->assertDontSee('Core cluster traffic');
});

it('adds and removes scoped workload firewall rules', function () {
    Queue::fake();
    $team = $this->user->teams()->firstOrFail();
    $cluster = CreateNodeCluster::run($team, $this->user, 'Firewall mesh');
    $node = Node::factory()->create(['team_id' => $team->id]);
    AssignNodeToCluster::run($cluster, $node);
    $source = NodeWorkload::factory()->create(['team_id' => $team->id]);
    $destination = NodeWorkload::factory()->create(['team_id' => $team->id]);
    EnsureNodeWorkloadAddress::run($node, $source);
    EnsureNodeWorkloadAddress::run($node, $destination);

    Livewire::test(Show::class, ['cluster_uuid' => $cluster->uuid])
        ->set('firewallSourceUuid', 'workload:'.$source->uuid)
        ->set('firewallDestinationUuid', $destination->uuid)
        ->set('firewallProtocol', 'tcp')
        ->set('firewallPort', 5432)
        ->call('addFirewallRule')
        ->assertDispatched('success')
        ->assertDispatched('firewall-rules-changed', fn (string $name, array $params): bool => count($params['rules']) === 1
            && $params['rules'][0]['sourceUuid'] === $source->uuid
            && $params['rules'][0]['destinationUuid'] === $destination->uuid
            && $params['rules'][0]['port'] === 5432);

    $rule = NodeFirewallRule::query()->sole();
    expect($rule->source_workload_id)->toBe($source->id)
        ->and($rule->destination_workload_id)->toBe($destination->id)
        ->and($rule->port)->toBe(5432)
        ->and($cluster->refresh()->desired_revision)->toBe(3);

    Livewire::test(Show::class, ['cluster_uuid' => $cluster->uuid])
        ->call('removeFirewallRule', $rule->uuid)
        ->assertDispatched('success')
        ->assertDispatched('firewall-rules-changed', rules: []);

    expect(NodeFirewallRule::query()->exists())->toBeFalse()
        ->and($cluster->refresh()->desired_revision)->toBe(4);
    Queue::assertPushed(ReconcileNodeClusterNetworkJob::class, 2);
});

it('creates a scoped firewall rule through the canvas action', function () {
    Queue::fake();
    $team = $this->user->teams()->firstOrFail();
    $cluster = CreateNodeCluster::run($team, $this->user, 'Canvas firewall mesh');
    $node = Node::factory()->create(['team_id' => $team->id]);
    AssignNodeToCluster::run($cluster, $node);
    $source = NodeWorkload::factory()->create(['team_id' => $team->id]);
    $destination = NodeWorkload::factory()->create(['team_id' => $team->id]);
    EnsureNodeWorkloadAddress::run($node, $source);
    EnsureNodeWorkloadAddress::run($node, $destination);

    Livewire::test(Show::class, ['cluster_uuid' => $cluster->uuid])
        ->call('createFirewallRule', 'workload', $source->uuid, $destination->uuid, 'udp', 53)
        ->assertDispatched('success');

    $rule = NodeFirewallRule::query()->sole();
    expect($rule->source_workload_id)->toBe($source->id)
        ->and($rule->destination_workload_id)->toBe($destination->id)
        ->and($rule->protocol)->toBe('udp')
        ->and($rule->port)->toBe(53);
    Queue::assertPushed(ReconcileNodeClusterNetworkJob::class);
});

it('adds an ICMP firewall rule without a port', function () {
    Queue::fake();
    $team = $this->user->teams()->firstOrFail();
    $cluster = CreateNodeCluster::run($team, $this->user, 'ICMP mesh');
    $node = Node::factory()->create(['team_id' => $team->id]);
    AssignNodeToCluster::run($cluster, $node);
    $source = NodeWorkload::factory()->create(['team_id' => $team->id]);
    $destination = NodeWorkload::factory()->create(['team_id' => $team->id]);
    EnsureNodeWorkloadAddress::run($node, $source);
    EnsureNodeWorkloadAddress::run($node, $destination);

    Livewire::test(Show::class, ['cluster_uuid' => $cluster->uuid])
        ->set('firewallSourceUuid', 'workload:'.$source->uuid)
        ->set('firewallDestinationUuid', $destination->uuid)
        ->set('firewallProtocol', 'icmp')
        ->call('addFirewallRule')
        ->assertDispatched('success');

    $rule = NodeFirewallRule::query()->sole();
    expect($rule->protocol)->toBe('icmp')
        ->and($rule->port)->toBe(0);
});

it('adds a firewall rule from a Node to a workload', function () {
    Queue::fake();
    $team = $this->user->teams()->firstOrFail();
    $cluster = CreateNodeCluster::run($team, $this->user, 'Node source mesh');
    $node = Node::factory()->create(['team_id' => $team->id]);
    AssignNodeToCluster::run($cluster, $node);
    $destination = NodeWorkload::factory()->create(['team_id' => $team->id]);
    EnsureNodeWorkloadAddress::run($node, $destination);

    $this->get(route('node-cluster.firewall', $cluster->uuid))
        ->assertSuccessful()
        ->assertSee($node->name)
        ->assertSee('Servers allow only required cluster traffic by default.');

    Livewire::test(Show::class, ['cluster_uuid' => $cluster->uuid])
        ->set('firewallSourceUuid', 'node:'.$node->uuid)
        ->set('firewallDestinationUuid', $destination->uuid)
        ->set('firewallProtocol', 'icmp')
        ->call('addFirewallRule')
        ->assertDispatched('success');

    $rule = NodeFirewallRule::query()->sole();
    expect($rule->source_node_id)->toBe($node->id)
        ->and($rule->source_workload_id)->toBeNull()
        ->and($rule->destination_workload_id)->toBe($destination->id)
        ->and($rule->protocol)->toBe('icmp')
        ->and($rule->port)->toBe(0);
});

it('rejects firewall rules for workloads outside the mesh', function () {
    Queue::fake();
    $team = $this->user->teams()->firstOrFail();
    $cluster = CreateNodeCluster::run($team, $this->user, 'Own mesh');
    $node = Node::factory()->create(['team_id' => $team->id]);
    AssignNodeToCluster::run($cluster, $node);
    $source = NodeWorkload::factory()->create(['team_id' => $team->id]);
    EnsureNodeWorkloadAddress::run($node, $source);
    $foreign = NodeWorkload::factory()->create();

    Livewire::test(Show::class, ['cluster_uuid' => $cluster->uuid])
        ->set('firewallSourceUuid', 'workload:'.$source->uuid)
        ->set('firewallDestinationUuid', $foreign->uuid)
        ->set('firewallPort', 80)
        ->call('addFirewallRule')
        ->assertHasErrors('firewallDestinationUuid');

    expect(NodeFirewallRule::query()->exists())->toBeFalse();
});

it('does not allow the same application as source and destination', function () {
    Queue::fake();
    $team = $this->user->teams()->firstOrFail();
    $cluster = CreateNodeCluster::run($team, $this->user, 'Self rule mesh');
    $node = Node::factory()->create(['team_id' => $team->id]);
    AssignNodeToCluster::run($cluster, $node);
    $application = NodeWorkload::factory()->create(['team_id' => $team->id, 'name' => 'only-application']);
    EnsureNodeWorkloadAddress::run($node, $application);

    Livewire::test(Show::class, ['cluster_uuid' => $cluster->uuid])
        ->set('firewallDestinationUuid', $application->uuid)
        ->set('firewallSourceUuid', 'workload:'.$application->uuid)
        ->assertSet('firewallDestinationUuid', '')
        ->set('firewallDestinationUuid', $application->uuid)
        ->set('firewallPort', 80)
        ->call('addFirewallRule')
        ->assertHasErrors('firewallDestinationUuid');

    expect(NodeFirewallRule::query()->exists())->toBeFalse();
});

it('queues cluster network reconciliation once', function () {
    Queue::fake();
    $team = $this->user->teams()->firstOrFail();
    $cluster = CreateNodeCluster::run($team, $this->user, 'Mesh');
    AssignNodeToCluster::run($cluster, Node::factory()->create(['team_id' => $team->id]));

    Livewire::test(Show::class, ['cluster_uuid' => $cluster->uuid])
        ->assertSee('Sync network')
        ->call('reconcileNetwork')
        ->assertDispatched('success');

    expect($cluster->refresh()->network_status)->toBe('reconciling');
    Queue::assertPushed(ReconcileNodeClusterNetworkJob::class, 1);

    Livewire::test(Show::class, ['cluster_uuid' => $cluster->uuid])
        ->call('reconcileNetwork')
        ->assertDispatched('info');
    Queue::assertPushed(ReconcileNodeClusterNetworkJob::class, 1);
});

it('prevents members from reconciling cluster networking', function () {
    Queue::fake();
    $team = $this->user->teams()->firstOrFail();
    $cluster = CreateNodeCluster::run($team, $this->user, 'Protected mesh');
    AssignNodeToCluster::run($cluster, Node::factory()->create(['team_id' => $team->id]));
    $team->members()->updateExistingPivot($this->user->id, ['role' => 'member']);
    auth()->user()->unsetRelation('teams');

    Livewire::test(Show::class, ['cluster_uuid' => $cluster->uuid])
        ->call('reconcileNetwork')
        ->assertForbidden();

    Queue::assertNothingPushed();
});

it('runs the scoped SSH network repair from the cluster page', function () {
    $team = $this->user->teams()->firstOrFail();
    $cluster = CreateNodeCluster::run($team, $this->user, 'Repairable mesh');
    AssignNodeToCluster::run($cluster, Node::factory()->create(['team_id' => $team->id]));
    RepairNodeClusterNetwork::mock()
        ->shouldReceive('handle')
        ->once();

    $this->get(route('node-cluster.advanced', $cluster->uuid))
        ->assertSuccessful()
        ->assertSee('Repair network over SSH');

    Livewire::test(Show::class, ['cluster_uuid' => $cluster->uuid])
        ->call('repairNetwork')
        ->assertDispatched('success');
});

it('builds an SSH repair script that restores only Coolify network state', function () {
    $script = RepairNodeClusterNetwork::repairScript('coolify0');

    expect($script)
        ->toContain('set -euo pipefail')
        ->toContain('/var/lib/coolify/network/coolify0.last-good.conf')
        ->toContain('/var/lib/coolify/network/firewall.last-good.nft')
        ->toContain('delete table inet coolify_cluster')
        ->toContain('resolvectl dns "$interface" "$wireguard_address"')
        ->toContain('wg show "$interface" allowed-ips')
        ->toContain('for (field = 2; field <= NF; field++)')
        ->toContain('print "~" $4 "." $3 "." $2 "." $1 ".in-addr.arpa"')
        ->toContain('resolvectl domain "$interface" ~coolify.internal $reverse_domains')
        ->toContain('systemctl restart coolify-discovery-dns.service')
        ->toContain('systemctl restart sentinel.service')
        ->not->toContain('flush ruleset');
});

it('shows cluster pages under the Servers item in the sidebar', function () {
    $navbar = file_get_contents(resource_path('views/components/navbar.blade.php'));

    expect($navbar)
        ->toContain('title="Servers"')
        ->toContain("request()->is('server/*', 'servers', 'servers/*', 'cluster/*', 'cluster-server/*')")
        ->not->toContain('title="Clusters"')
        ->not->toContain("route('node-cluster.index')");
});

it('renders one page per cluster menu item with the grouped sidebar', function (string $routeName, array $expectedText) {
    $cluster = CreateNodeCluster::run($this->user->teams()->firstOrFail(), $this->user, 'Sectioned cluster');

    $response = $this->get(route($routeName, $cluster->uuid))
        ->assertSuccessful()
        ->assertSeeLivewire(Show::class)
        ->assertSee('Sectioned cluster')
        ->assertSee('Settings')
        ->assertSee('Network')
        ->assertSee('Danger zone')
        ->assertSee(route('node-cluster.show', $cluster->uuid), escape: false)
        ->assertSee(route('node-cluster.nodes', $cluster->uuid), escape: false)
        ->assertSee(route('node-cluster.firewall', $cluster->uuid), escape: false)
        ->assertSee(route('node-cluster.advanced', $cluster->uuid), escape: false)
        ->assertSee(route('node-cluster.delete', $cluster->uuid), escape: false)
        ->assertDontSee('Revision ')
        ->assertDontSee('Desired revision');

    foreach ($expectedText as $text) {
        $response->assertSee($text);
    }
})->with([
    'general' => ['node-cluster.show', ['Overview', 'Servers ready', 'Private network', 'Last synced', 'Sync network', 'Pending']],
    'nodes' => ['node-cluster.nodes', ['No servers in this cluster', 'Connect new server']],
    'firewall' => ['node-cluster.firewall', ['Traffic map', 'Application rules', 'System rules']],
    'advanced' => ['node-cluster.advanced', ['Private network', 'WireGuard interface', 'Deployment limits', 'Troubleshooting', 'Recent operations']],
    'danger' => ['node-cluster.delete', ['Delete cluster', 'This action cannot be undone']],
]);

it('derives the active section from the route', function (string $routeName, string $title) {
    $cluster = CreateNodeCluster::run($this->user->teams()->firstOrFail(), $this->user, 'Route sections');

    $this->get(route($routeName, $cluster->uuid))
        ->assertSuccessful()
        ->assertSee('Route sections > '.$title.' | Cluster | Coolify', false);
})->with([
    ['node-cluster.show', 'General'],
    ['node-cluster.nodes', 'Servers'],
    ['node-cluster.firewall', 'Firewall'],
    ['node-cluster.advanced', 'Advanced'],
    ['node-cluster.delete', 'Danger'],
]);

it('does not let the client change the locked section', function () {
    $cluster = CreateNodeCluster::run($this->user->teams()->firstOrFail(), $this->user, 'Locked section');

    expect(fn () => Livewire::test(Show::class, ['cluster_uuid' => $cluster->uuid])->set('section', 'danger'))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('denies cross-team access to every cluster page', function (string $routeName) {
    $foreign = NodeCluster::factory()->create();

    $this->get(route($routeName, $foreign->uuid))->assertNotFound();
})->with(['node-cluster.show', 'node-cluster.nodes', 'node-cluster.firewall', 'node-cluster.advanced', 'node-cluster.delete']);

it('lists cluster nodes with network state and offers unassigned nodes', function () {
    $team = $this->user->teams()->firstOrFail();
    $cluster = CreateNodeCluster::run($team, $this->user, 'Node list');
    $member = Node::factory()->create(['team_id' => $team->id, 'name' => 'Member node', 'is_usable' => true]);
    AssignNodeToCluster::run($cluster, $member);
    Node::factory()->create(['team_id' => $team->id, 'name' => 'Spare node']);

    $this->get(route('node-cluster.nodes', $cluster->uuid))
        ->assertSuccessful()
        ->assertSee('Member node')
        ->assertSee($member->fresh()->wireguard_ip)
        ->assertSee('Ready')
        ->assertSee('Pending')
        ->assertSee('Add server')
        ->assertSee('Spare node')
        ->assertSee('Remove')
        ->assertSee('node-cluster-nodes-warning', escape: false)
        ->assertDontSee('Discovery');
});

it('shows a node network as in sync only when the revision is applied and discovery converged', function () {
    $team = $this->user->teams()->firstOrFail();
    $cluster = CreateNodeCluster::run($team, $this->user, 'Sync state');
    $node = Node::factory()->create(['team_id' => $team->id, 'is_usable' => true]);
    AssignNodeToCluster::run($cluster, $node);
    $cluster->refresh();

    $node->update(['network_applied_revision' => $cluster->desired_revision, 'corrosion_status' => 'syncing']);
    expect($cluster->isNodeNetworkInSync($node->fresh()))->toBeFalse()
        ->and($cluster->hasNodesNeedingAttention())->toBeTrue();

    $node->update(['corrosion_status' => 'converged']);
    expect($cluster->isNodeNetworkInSync($node->fresh()))->toBeTrue()
        ->and($cluster->hasNodesNeedingAttention())->toBeFalse();

    $this->get(route('node-cluster.nodes', $cluster->uuid))
        ->assertSee('In sync')
        ->assertDontSee('node-cluster-nodes-warning', escape: false);
});

it('maps the cluster network status to a typed badge', function (?string $status, string $label, string $type) {
    $cluster = NodeCluster::factory()->make(['network_status' => $status]);

    expect($cluster->networkStatusLabel())->toBe($label)
        ->and($cluster->networkStatusBadgeType())->toBe($type);
})->with([
    ['active', 'Active', 'success'],
    ['applied', 'Active', 'success'],
    ['degraded', 'Degraded', 'warning'],
    ['reconciling', 'Syncing', 'warning'],
    ['error', 'Failed', 'error'],
    ['failed', 'Failed', 'error'],
    ['pending', 'Pending', 'neutral'],
    [null, 'Pending', 'neutral'],
]);

it('hides mutating controls and the danger page link from members', function () {
    $team = $this->user->teams()->firstOrFail();
    $cluster = CreateNodeCluster::run($team, $this->user, 'Read only cluster');
    AssignNodeToCluster::run($cluster, Node::factory()->create(['team_id' => $team->id, 'name' => 'Visible node']));
    Node::factory()->create(['team_id' => $team->id, 'name' => 'Unassigned spare']);
    $team->members()->updateExistingPivot($this->user->id, ['role' => 'member']);
    auth()->user()->unsetRelation('teams');

    $this->get(route('node-cluster.show', $cluster->uuid))
        ->assertSuccessful()
        ->assertDontSee('Sync network')
        ->assertDontSee(route('node-cluster.delete', $cluster->uuid), escape: false);

    $this->get(route('node-cluster.nodes', $cluster->uuid))
        ->assertSuccessful()
        ->assertSee('Visible node')
        ->assertDontSee('Unassigned spare')
        ->assertDontSee('wire:click="removeNode', escape: false);

    $this->get(route('node-cluster.advanced', $cluster->uuid))
        ->assertSuccessful()
        ->assertDontSee('Repair network over SSH');

    $this->get(route('node-cluster.delete', $cluster->uuid))
        ->assertSuccessful()
        ->assertDontSee('Confirm Cluster Deletion?');
});

it('requires the account password before deleting a cluster', function () {
    $cluster = CreateNodeCluster::run($this->user->teams()->firstOrFail(), $this->user, 'Password protected');

    Livewire::test(Show::class, ['cluster_uuid' => $cluster->uuid])
        ->call('deleteCluster', 'wrong-password')
        ->assertHasErrors('password')
        ->assertNoRedirect();

    expect($cluster->fresh())->not->toBeNull();
});

it('keeps the delete error toast when a cluster still has applications', function () {
    $team = $this->user->teams()->firstOrFail();
    $cluster = CreateNodeCluster::run($team, $this->user, 'Busy cluster');
    $node = Node::factory()->create(['team_id' => $team->id]);
    AssignNodeToCluster::run($cluster, $node);
    EnsureNodeWorkloadAddress::run($node->fresh(), NodeWorkload::factory()->create(['team_id' => $team->id]));

    Livewire::test(Show::class, ['cluster_uuid' => $cluster->uuid])
        ->call('deleteCluster', 'password')
        ->assertDispatched('error')
        ->assertNoRedirect();

    expect($cluster->fresh())->not->toBeNull();
});

it('shows recent operations with readable labels on the advanced page', function () {
    $team = $this->user->teams()->firstOrFail();
    $cluster = CreateNodeCluster::run($team, $this->user, 'Operations cluster');
    $node = Node::factory()->create(['team_id' => $team->id, 'name' => 'Ops node']);
    AssignNodeToCluster::run($cluster, $node);
    NodeOperation::factory()->create(['node_id' => $node->id, 'command_type' => 'network.cluster.apply.v1']);

    $this->get(route('node-cluster.advanced', $cluster->uuid))
        ->assertSuccessful()
        ->assertSee('Network Cluster Apply')
        ->assertSee('Ops node')
        ->assertDontSee('network.cluster.apply.v1');
});

it('hides successful background checks from recent operations', function () {
    $team = $this->user->teams()->firstOrFail();
    $cluster = CreateNodeCluster::run($team, $this->user, 'Background cluster');
    $node = Node::factory()->create(['team_id' => $team->id, 'name' => 'Background node']);
    AssignNodeToCluster::run($cluster, $node);
    NodeOperation::factory()->create(['node_id' => $node->id, 'command_type' => 'network.firewall.inspect.v1', 'status' => NodeOperationStatus::SUCCEEDED]);
    NodeOperation::factory()->create(['node_id' => $node->id, 'command_type' => 'discovery.corrosion.inspect.v1', 'status' => NodeOperationStatus::FAILED]);
    NodeOperation::factory()->create(['node_id' => $node->id, 'command_type' => 'network.wireguard.reconcile.v1', 'status' => NodeOperationStatus::SUCCEEDED]);

    $this->get(route('node-cluster.advanced', $cluster->uuid))
        ->assertSuccessful()
        ->assertDontSee('Network Firewall Inspect')
        ->assertSee('Discovery Corrosion Inspect')
        ->assertSee('Network Wireguard Reconcile');
});

it('lists each cluster with its servers before servers without a cluster', function () {
    $team = $this->user->teams()->firstOrFail();
    $cluster = CreateNodeCluster::run($team, $this->user, 'Index cluster');
    $assigned = Node::factory()->create(['team_id' => $team->id, 'name' => 'Assigned node', 'ip' => '203.0.113.10']);
    AssignNodeToCluster::run($cluster, $assigned);
    Node::factory()->create(['team_id' => $team->id, 'name' => 'Loose node']);

    Livewire::test(Index::class)
        ->assertSeeInOrder(['Index cluster', '1 server', 'Assigned node', 'Not in a cluster', 'Loose node'])
        ->assertSee('203.0.113.10')
        ->assertSee('New cluster')
        ->assertSee('Pending')
        ->assertSee(route('node-cluster.show', ['cluster_uuid' => $cluster->uuid]), escape: false)
        ->assertDontSeeHtml('>Dev<');
});
