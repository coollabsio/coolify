<?php

use App\Actions\Node\AssignNodeToCluster;
use App\Actions\Node\CreateNodeCluster;
use App\Actions\Node\EnsureNodeWorkloadAddress;
use App\Actions\Node\RemoveNodeFromCluster;
use App\Actions\Node\RepairNodeClusterNetwork;
use App\Actions\Node\UpdateNodeCluster;
use App\Jobs\ReconcileNodeClusterNetworkJob;
use App\Livewire\NodeCluster\Index;
use App\Livewire\NodeCluster\Show;
use App\Models\InstanceSettings;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\NodeFirewallRule;
use App\Models\NodeIngressRule;
use App\Models\NodeWorkload;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
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

it('uses the standard collection controls on the cluster index', function () {
    $view = file_get_contents(resource_path('views/livewire/node-cluster/index.blade.php'))
        .file_get_contents(resource_path('views/livewire/shared/list-search-controls.blade.php'));

    expect($view)
        ->toContain('<x-slot:title>Clusters | Coolify</x-slot>')
        ->toContain('>Clusters</h1>')
        ->toContain('New cluster')
        ->toContain('Search clusters')
        ->toContain("viewMode === 'table'")
        ->toContain("viewMode === 'grid'")
        ->toContain('control-selected')
        ->toContain("localStorage.setItem('coolify-node-clusters-view', mode)")
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

    RemoveNodeFromCluster::run($cluster->refresh(), $node->refresh());

    expect($node->refresh()->node_cluster_id)->toBeNull()
        ->and($node->wireguard_ip)->toBeNull()
        ->and($cluster->refresh()->desired_revision)->toBe(3);
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
});

it('prevents members from changing firewall rules', function () {
    $team = $this->user->teams()->firstOrFail();
    $cluster = CreateNodeCluster::run($team, $this->user, 'Protected firewall');
    $team->members()->updateExistingPivot($this->user->id, ['role' => 'member']);

    Livewire::test(Show::class, ['cluster_uuid' => $cluster->uuid])
        ->call('addFirewallRule')
        ->assertForbidden();

    Livewire::test(Show::class, ['cluster_uuid' => $cluster->uuid])
        ->call('addIngressRule')
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

it('prevents deletion while nodes are assigned', function () {
    $team = $this->user->teams()->firstOrFail();
    $cluster = CreateNodeCluster::run($team, $this->user, 'Used');
    AssignNodeToCluster::run($cluster, Node::factory()->create(['team_id' => $team->id]));

    Livewire::test(Show::class, ['cluster_uuid' => $cluster->uuid])
        ->call('deleteCluster')
        ->assertForbidden();
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
        ->call('deleteCluster');

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

    Livewire::test(Show::class, ['cluster_uuid' => $cluster->uuid])
        ->assertSee('Core cluster traffic')
        ->assertSee('System managed')
        ->assertSee('WireGuard')
        ->assertSee('UDP / 51820')
        ->assertSee('Corrosion gossip')
        ->assertSee('UDP / 8787')
        ->assertSee('Corrosion local API')
        ->assertSee('TCP / 8080')
        ->assertSee('Workload DNS')
        ->assertSee('TCP + UDP / 53')
        ->assertSee('Established connections');
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
        ->assertDispatched('success');

    $rule = NodeFirewallRule::query()->sole();
    expect($rule->source_workload_id)->toBe($source->id)
        ->and($rule->destination_workload_id)->toBe($destination->id)
        ->and($rule->port)->toBe(5432)
        ->and($cluster->refresh()->desired_revision)->toBe(3);

    Livewire::test(Show::class, ['cluster_uuid' => $cluster->uuid])
        ->call('removeFirewallRule', $rule->uuid)
        ->assertDispatched('success');

    expect(NodeFirewallRule::query()->exists())->toBeFalse()
        ->and($cluster->refresh()->desired_revision)->toBe(4);
    Queue::assertPushed(ReconcileNodeClusterNetworkJob::class, 2);
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

    Livewire::test(Show::class, ['cluster_uuid' => $cluster->uuid])
        ->assertSee($node->name)
        ->assertSee('Nodes allow only required cluster traffic by default.')
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

it('adds and removes scoped workload ingress rules', function () {
    Queue::fake();
    $team = $this->user->teams()->firstOrFail();
    $cluster = CreateNodeCluster::run($team, $this->user, 'Ingress mesh');
    $node = Node::factory()->create(['team_id' => $team->id]);
    AssignNodeToCluster::run($cluster, $node);
    $destination = NodeWorkload::factory()->create(['team_id' => $team->id]);
    EnsureNodeWorkloadAddress::run($node, $destination);

    Livewire::test(Show::class, ['cluster_uuid' => $cluster->uuid])
        ->set('ingressDestinationUuid', $destination->uuid)
        ->set('ingressProtocol', 'tcp')
        ->set('ingressPort', 8080)
        ->call('addIngressRule')
        ->assertDispatched('success');

    $rule = NodeIngressRule::query()->sole();
    expect($rule->destination_workload_id)->toBe($destination->id)
        ->and($rule->port)->toBe(8080)
        ->and($cluster->refresh()->desired_revision)->toBe(3);

    Livewire::test(Show::class, ['cluster_uuid' => $cluster->uuid])
        ->call('removeIngressRule', $rule->uuid)
        ->assertDispatched('success');

    expect(NodeIngressRule::query()->exists())->toBeFalse()
        ->and($cluster->refresh()->desired_revision)->toBe(4);
    Queue::assertPushed(ReconcileNodeClusterNetworkJob::class, 2);
});

it('rejects ingress rules for workloads outside the mesh', function () {
    Queue::fake();
    $cluster = CreateNodeCluster::run($this->user->teams()->firstOrFail(), $this->user, 'Own ingress mesh');
    $foreign = NodeWorkload::factory()->create();

    Livewire::test(Show::class, ['cluster_uuid' => $cluster->uuid])
        ->set('ingressDestinationUuid', $foreign->uuid)
        ->set('ingressPort', 80)
        ->call('addIngressRule')
        ->assertHasErrors('ingressDestinationUuid');

    expect(NodeIngressRule::query()->exists())->toBeFalse();
});

it('queues cluster network reconciliation once', function () {
    Queue::fake();
    $team = $this->user->teams()->firstOrFail();
    $cluster = CreateNodeCluster::run($team, $this->user, 'Mesh');
    AssignNodeToCluster::run($cluster, Node::factory()->create(['team_id' => $team->id]));

    Livewire::test(Show::class, ['cluster_uuid' => $cluster->uuid])
        ->assertSee('Reconcile network')
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

    Livewire::test(Show::class, ['cluster_uuid' => $cluster->uuid])
        ->assertSee('Repair over SSH')
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

it('uses the Clusters label and layers icon in the sidebar', function () {
    $navbar = file_get_contents(resource_path('views/components/navbar.blade.php'));

    expect($navbar)
        ->toContain('title="Clusters"')
        ->toContain("request()->is('node-clusters*') || request()->is('node/*')")
        ->toContain('<x-reicon name="layers" class="menu-item-icon" />')
        ->toContain('>Clusters</span>')
        ->not->toContain('title="Node clusters"');
});
