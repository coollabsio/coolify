<?php

use App\Enums\NodeContainerManagementState;
use App\Livewire\Dashboard;
use App\Livewire\Dashboard\ClusterMap;
use App\Models\InstanceSettings;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\NodeContainer;
use App\Models\NodeFirewallRule;
use App\Models\NodeWorkload;
use App\Models\NodeWorkloadRevision;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    InstanceSettings::forceCreate(['id' => 0]);
    config()->set('app.env', 'local');
    config()->set('constants.sentinel.host_enabled', true);

    $this->user = User::factory()->create();
    $this->team = $this->user->teams()->firstOrFail();
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
    $this->privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
});

function mapNode(Team $team, NodeCluster $cluster, string $name, array $attributes = []): Node
{
    return Node::factory()->create([
        'team_id' => $team->id,
        'node_cluster_id' => $cluster->id,
        'private_key_id' => PrivateKey::query()->firstOrFail()->id,
        'name' => $name,
        'ip' => fake()->unique()->ipv4(),
        'wireguard_ip' => '10.250.9.'.fake()->unique()->numberBetween(2, 250),
        'is_reachable' => true,
        'is_usable' => true,
        ...$attributes,
    ]);
}

function mapApp(Team $team, string $name, array $attributes = []): NodeWorkload
{
    return NodeWorkload::factory()->create(['team_id' => $team->id, 'name' => $name, ...$attributes]);
}

function mapContainer(Node $node, NodeWorkload $workload, NodeWorkloadRevision $revision, string $state): NodeContainer
{
    return NodeContainer::factory()->create([
        'node_id' => $node->id,
        'node_workload_id' => $workload->id,
        'node_workload_revision_id' => $revision->id,
        'state' => $state,
        'management_state' => NodeContainerManagementState::MANAGED,
        'is_managed' => true,
    ]);
}

/** @return array<string, mixed> */
function clusterMapData(): array
{
    return Livewire::test(ClusterMap::class)->get('map');
}

it('hides the map toggle when the cluster stack is disabled', function () {
    config()->set('constants.sentinel.host_enabled', false);
    NodeCluster::factory()->create(['team_id' => $this->team->id, 'name' => 'Hidden mesh']);

    Livewire::test(Dashboard::class)
        ->assertViewHas('clusterMapAvailable', false)
        ->assertDontSeeHtml('aria-label="Map view"')
        ->assertDontSeeHtml('dashboard-cluster-map');
});

it('hides the map toggle when the team has no cluster', function () {
    NodeCluster::factory()->create(['team_id' => Team::factory()->create()->id, 'name' => 'Other team mesh']);

    Livewire::test(Dashboard::class)
        ->assertViewHas('clusterMapAvailable', false)
        ->assertDontSeeHtml('aria-label="Map view"')
        ->assertDontSeeHtml('dashboard-cluster-map');
});

it('offers a remembered list and map toggle when the team has a cluster', function () {
    NodeCluster::factory()->create(['team_id' => $this->team->id, 'name' => 'Visible mesh']);

    Livewire::test(Dashboard::class)
        ->assertViewHas('clusterMapAvailable', true)
        ->assertSeeHtml('aria-label="List view"')
        ->assertSeeHtml('aria-label="Map view"')
        ->assertSeeHtml('coolify-dashboard-servers-view')
        ->assertSeeLivewire(ClusterMap::class)
        ->assertSee(route('server.index'), false);
});

it('refreshes the map every fifteen seconds only while it is visible', function () {
    NodeCluster::factory()->create(['team_id' => $this->team->id]);

    Livewire::test(ClusterMap::class)
        ->assertSeeHtml('wire:poll.15s.visible="refreshMap"')
        ->assertSeeHtml('x-data="clusterMap"');
});

it('rejects the map component when the cluster stack is disabled', function () {
    config()->set('constants.sentinel.host_enabled', false);

    Livewire::test(ClusterMap::class)->assertStatus(404);
});

it('maps clusters, servers, and the applications on each server', function () {
    $cluster = NodeCluster::factory()->create(['team_id' => $this->team->id, 'name' => 'Production mesh', 'network_status' => 'degraded']);
    $alpha = mapNode($this->team, $cluster, 'Alpha', [
        'is_ingress' => true,
        'metadata' => ['container_inventory_observed_at' => now()->toIso8601String()],
    ]);
    $beta = mapNode($this->team, $cluster, 'Beta', [
        'metadata' => ['container_inventory_observed_at' => now()->toIso8601String()],
    ]);
    mapNode($this->team, $cluster, 'Gamma', ['is_reachable' => false]);
    Cache::put($alpha->cacheKey(), ['status' => 'connected']);

    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = $project->environments()->firstOrFail();
    $api = mapApp($this->team, 'api', ['project_id' => $project->id, 'environment_id' => $environment->id]);
    $alpha->workloads()->attach($api);
    $beta->workloads()->attach($api);
    $revision = NodeWorkloadRevision::factory()->create(['node_workload_id' => $api->id]);
    mapContainer($alpha, $api, $revision, 'running');
    mapContainer($beta, $api, $revision, 'exited');

    $map = clusterMapData();

    expect($map['clusters'])->toHaveCount(1);
    $clusterData = $map['clusters'][0];
    expect($clusterData)
        ->uuid->toBe($cluster->uuid)
        ->name->toBe('Production mesh')
        ->status->toBe('Degraded')
        ->statusType->toBe('warning')
        ->href->toBe(route('node-cluster.show', ['cluster_uuid' => $cluster->uuid]))
        ->firewallHref->toBe(route('node-cluster.firewall', ['cluster_uuid' => $cluster->uuid]));

    $servers = collect($clusterData['servers'])->keyBy('name');
    expect($servers->keys()->all())->toBe(['Alpha', 'Beta', 'Gamma'])
        ->and($servers['Alpha'])->toMatchArray(['status' => 'Ready', 'statusType' => 'success', 'ingress' => 'Pending', 'ingressType' => 'warning', 'href' => route('node.show', ['node_uuid' => $alpha->uuid])])
        ->and($servers['Beta'])->toMatchArray(['status' => 'Sentinel disconnected', 'statusType' => 'warning', 'ingress' => 'Off'])
        ->and($servers['Gamma'])->toMatchArray(['status' => 'Unreachable', 'statusType' => 'error', 'apps' => []]);

    // An application on two servers appears in both server cards, with the state on each server.
    expect($servers['Alpha']['apps'])->toBe([[
        'uuid' => $api->uuid,
        'name' => 'api',
        'href' => route('project.cluster-application.show', [
            'project_uuid' => $project->uuid,
            'environment_uuid' => $environment->uuid,
            'workload_uuid' => $api->uuid,
        ]),
        'status' => 'Running',
        'statusType' => 'success',
    ]])
        ->and($servers['Beta']['apps'])->toHaveCount(1)
        ->and($servers['Beta']['apps'][0])->toMatchArray(['uuid' => $api->uuid, 'status' => 'Stopped', 'statusType' => 'warning']);
});

it('reports unknown and missing application states', function () {
    $cluster = NodeCluster::factory()->create(['team_id' => $this->team->id]);
    $withoutInventory = mapNode($this->team, $cluster, 'No inventory');
    $withInventory = mapNode($this->team, $cluster, 'With inventory', ['metadata' => ['container_inventory_observed_at' => now()->toIso8601String()]]);
    $app = mapApp($this->team, 'worker');
    $withoutInventory->workloads()->attach($app);
    $withInventory->workloads()->attach($app);

    $servers = collect(clusterMapData()['clusters'][0]['servers'])->keyBy('name');

    expect($servers['No inventory']['apps'][0])->toMatchArray(['status' => 'Unknown', 'statusType' => 'neutral', 'href' => null])
        ->and($servers['With inventory']['apps'][0])->toMatchArray(['status' => 'Missing', 'statusType' => 'warning']);
});

it('includes firewall rules and public domains as traffic', function () {
    $cluster = NodeCluster::factory()->create(['team_id' => $this->team->id]);
    $node = mapNode($this->team, $cluster, 'Edge');
    $web = mapApp($this->team, 'web', ['domains' => ['shop.example.com', 'www.shop.example.com'], 'http_port' => 8080]);
    $database = mapApp($this->team, 'database');
    $internal = mapApp($this->team, 'internal', ['domains' => ['ignored.example.com']]);
    $node->workloads()->attach([$web->id, $database->id, $internal->id]);
    $appRule = NodeFirewallRule::factory()->create([
        'node_cluster_id' => $cluster->id,
        'source_workload_id' => $web->id,
        'destination_workload_id' => $database->id,
        'protocol' => 'tcp',
        'port' => 5432,
    ]);
    $serverRule = NodeFirewallRule::factory()->create([
        'node_cluster_id' => $cluster->id,
        'source_workload_id' => null,
        'source_node_id' => $node->id,
        'destination_workload_id' => $web->id,
        'protocol' => 'icmp',
        'port' => 0,
    ]);
    $elsewhere = mapApp($this->team, 'elsewhere');
    NodeFirewallRule::factory()->create([
        'node_cluster_id' => $cluster->id,
        'source_workload_id' => $web->id,
        'destination_workload_id' => $elsewhere->id,
    ]);

    $clusterData = clusterMapData()['clusters'][0];

    expect($clusterData['rules'])->toBe([
        ['uuid' => $appRule->uuid, 'sourceType' => 'workload', 'sourceUuid' => $web->uuid, 'destinationUuid' => $database->uuid, 'protocol' => 'tcp', 'port' => 5432],
        ['uuid' => $serverRule->uuid, 'sourceType' => 'node', 'sourceUuid' => $node->uuid, 'destinationUuid' => $web->uuid, 'protocol' => 'icmp', 'port' => 0],
    ])->and($clusterData['ingress'])->toBe([
        ['appUuid' => $web->uuid, 'domains' => ['shop.example.com', 'www.shop.example.com'], 'port' => 8080],
    ]);
});

it('lists the Docker servers of the team with their status', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $this->privateKey->id, 'name' => 'localhost-docker']);
    $server->settings->update(['is_reachable' => false, 'is_usable' => false]);
    NodeCluster::factory()->create(['team_id' => $this->team->id]);

    $map = clusterMapData();

    expect($map['dockerServers'])->toBe([[
        'uuid' => $server->uuid,
        'name' => 'localhost-docker',
        'href' => route('server.show', ['server_uuid' => $server->uuid]),
        'status' => 'Unavailable',
        'statusType' => 'error',
    ]])->and($map['dockerServersTotal'])->toBe(1);
});

it('only maps the current team and never includes IP addresses', function () {
    $cluster = NodeCluster::factory()->create(['team_id' => $this->team->id, 'name' => 'Own mesh', 'cidr' => '10.250.200.0/24']);
    $node = mapNode($this->team, $cluster, 'Own server', ['ip' => '203.0.113.10', 'wireguard_ip' => '10.250.200.2', 'workload_cidr' => '100.64.7.0/24']);
    $app = mapApp($this->team, 'own-app');
    $node->workloads()->attach($app, ['container_ip' => '100.64.7.2']);
    $dockerServer = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $this->privateKey->id, 'ip' => '198.51.100.20']);

    $otherTeam = Team::factory()->create();
    $otherCluster = NodeCluster::factory()->create(['team_id' => $otherTeam->id, 'name' => 'Foreign mesh']);
    $otherNode = mapNode($otherTeam, $otherCluster, 'Foreign server');
    $otherApp = mapApp($otherTeam, 'foreign-app');
    $otherNode->workloads()->attach($otherApp);
    // A foreign application attached to an own server must not leak through the placement.
    $node->workloads()->attach(mapApp($otherTeam, 'foreign-on-own-server'));
    NodeFirewallRule::factory()->create(['node_cluster_id' => $otherCluster->id, 'source_workload_id' => $otherApp->id, 'destination_workload_id' => $otherApp->id]);
    Server::factory()->create(['team_id' => $otherTeam->id, 'private_key_id' => $otherNode->private_key_id, 'name' => 'Foreign docker']);

    $map = clusterMapData();
    $json = json_encode($map, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    expect(collect($map['clusters'])->pluck('name')->all())->toBe(['Own mesh'])
        ->and(collect($map['clusters'][0]['servers'][0]['apps'])->pluck('name')->all())->toBe(['own-app'])
        ->and(collect($map['dockerServers'])->pluck('uuid')->all())->toBe([$dockerServer->uuid])
        ->and($json)->not->toContain('Foreign')
        ->and($json)->not->toContain('foreign')
        ->and($json)->not->toContain('203.0.113.10')
        ->and($json)->not->toContain('10.250.200.')
        ->and($json)->not->toContain('100.64.7.')
        ->and($json)->not->toContain('198.51.100.20')
        ->and($json)->not->toContain('"ip"');
});

it('shows the map to team members', function () {
    $member = User::factory()->create();
    $member->teams()->attach($this->team, ['role' => 'member']);
    $this->actingAs($member);
    session(['currentTeam' => $this->team]);
    $cluster = NodeCluster::factory()->create(['team_id' => $this->team->id, 'name' => 'Member mesh']);
    mapNode($this->team, $cluster, 'Member server');

    Livewire::test(Dashboard::class)->assertViewHas('clusterMapAvailable', true);
    expect(clusterMapData()['clusters'][0]['servers'][0]['name'])->toBe('Member server');
});

it('loads the map with a fixed number of queries', function () {
    $seed = function (int $clusters, int $servers, int $apps): void {
        foreach (range(1, $clusters) as $clusterIndex) {
            $cluster = NodeCluster::factory()->create(['team_id' => $this->team->id]);
            $nodes = collect(range(1, $servers))->map(fn () => mapNode($this->team, $cluster, fake()->unique()->word(), [
                'metadata' => ['container_inventory_observed_at' => now()->toIso8601String()],
            ]));
            $workloads = collect(range(1, $apps))->map(function () use ($nodes) {
                $workload = mapApp($this->team, fake()->unique()->word(), ['domains' => [fake()->unique()->domainName()], 'http_port' => 80]);
                $revision = NodeWorkloadRevision::factory()->create(['node_workload_id' => $workload->id]);
                $nodes->each(function (Node $node) use ($workload, $revision): void {
                    $node->workloads()->attach($workload);
                    mapContainer($node, $workload, $revision, 'running');
                });

                return $workload;
            });
            NodeFirewallRule::factory()->create([
                'node_cluster_id' => $cluster->id,
                'source_workload_id' => $workloads->first()->id,
                'destination_workload_id' => $workloads->last()->id,
            ]);
        }
        Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $this->privateKey->id]);
    };
    $countQueries = function (): int {
        $component = Livewire::test(ClusterMap::class);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $component->call('refreshMap');
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $seed(1, 1, 2);
    $small = $countQueries();
    $seed(2, 3, 4);
    $large = $countQueries();

    expect(collect(clusterMapData()['clusters'])->sum(fn (array $cluster) => collect($cluster['servers'])->sum(fn (array $server) => count($server['apps']))))->toBe(2 + 2 * 3 * 4)
        ->and($large)->toBe($small)
        ->and($large)->toBeLessThanOrEqual(15);
});
