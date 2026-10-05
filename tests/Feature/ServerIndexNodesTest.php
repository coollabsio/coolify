<?php

use App\Actions\Node\AssignNodeToCluster;
use App\Actions\Node\CreateNodeCluster;
use App\Actions\Node\FetchLatestSentinelRelease;
use App\Livewire\NodeCluster\Index as ClusterGroups;
use App\Livewire\Server\Index as ServerIndex;
use App\Models\InstanceSettings;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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
    Cache::put(FetchLatestSentinelRelease::CACHE_KEY, ['release' => ['version' => '1.1.0', 'digest' => 'sha256:'.str_repeat('a', 64)]]);
});

function serverIndexDockerServer(int $teamId, string $name): Server
{
    return Server::factory()->create([
        'team_id' => $teamId,
        'name' => $name,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $teamId])->id,
    ]);
}

it('groups cluster servers by cluster above the Docker servers', function () {
    $cluster = CreateNodeCluster::run($this->team, $this->user, 'Development QEMU mesh');
    $cluster->update(['network_status' => 'active']);
    $member = Node::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'QEMU worker',
        'ip' => '203.0.113.21',
        'is_reachable' => true,
        'is_usable' => true,
        'sentinel_version' => '1.0.0',
    ]);
    AssignNodeToCluster::run($cluster, $member);
    $loose = Node::factory()->create(['team_id' => $this->team->id, 'name' => 'Loose cluster server', 'is_reachable' => false]);
    serverIndexDockerServer($this->team->id, 'Docker host one');

    Livewire::test(ServerIndex::class)
        ->assertSeeLivewire(ClusterGroups::class)
        ->assertSee('Add server')
        ->assertSeeInOrder(['Clusters', 'Docker servers', 'Docker host one'])
        ->assertDontSee('Node');

    Livewire::test(ClusterGroups::class)
        ->assertSeeInOrder(['Development QEMU mesh', 'Active', '1 server', 'QEMU worker', 'Not in a cluster', 'Loose cluster server'])
        ->assertSee('203.0.113.21')
        ->assertSee('1.0.0')
        ->assertSee('Upgrade available')
        ->assertSee('Unreachable')
        ->assertSee('Off')
        ->assertSee('Cluster settings')
        ->assertSee(route('node-cluster.show', ['cluster_uuid' => $cluster->uuid]), escape: false)
        ->assertSee(route('node.show', ['node_uuid' => $member->uuid]), escape: false)
        ->assertSee(route('node.show', ['node_uuid' => $loose->uuid]), escape: false)
        ->assertDontSee('Node');
});

it('shows the cluster ingress state of each cluster server', function () {
    $cluster = CreateNodeCluster::run($this->team, $this->user, 'Ingress mesh');
    $ingress = Node::factory()->create(['team_id' => $this->team->id, 'name' => 'Edge server', 'is_ingress' => true]);
    AssignNodeToCluster::run($cluster, $ingress);

    Livewire::test(ClusterGroups::class)->assertSee('Edge server')->assertSee('Pending');

    $ingress->refresh()->mergeMetadata(['ingress_active' => true, 'ingress_applied_revision' => $cluster->refresh()->desired_revision]);

    Livewire::test(ClusterGroups::class)->assertSee('Edge server')->assertSee('Active');
});

it('hides clusters, cluster servers, and Docker servers of another team', function () {
    $otherUser = User::factory()->create();
    $otherTeam = $otherUser->teams()->firstOrFail();
    $foreignCluster = NodeCluster::factory()->create(['team_id' => $otherTeam->id, 'name' => 'Foreign mesh']);
    Node::factory()->create(['team_id' => $otherTeam->id, 'name' => 'Foreign cluster server', 'node_cluster_id' => $foreignCluster->id]);
    Node::factory()->create(['team_id' => $otherTeam->id, 'name' => 'Foreign loose server']);
    serverIndexDockerServer($otherTeam->id, 'Foreign Docker host');

    Livewire::test(ServerIndex::class)->assertDontSee('Foreign Docker host');
    Livewire::test(ClusterGroups::class)
        ->assertDontSee('Foreign mesh')
        ->assertDontSee('Foreign cluster server')
        ->assertDontSee('Foreign loose server')
        ->assertDontSee('Not in a cluster')
        ->assertSee('No clusters yet');
});

it('shows empty states for a cluster without servers and for missing Docker servers', function () {
    CreateNodeCluster::run($this->team, $this->user, 'Empty mesh');

    Livewire::test(ClusterGroups::class)
        ->assertSee('Empty mesh')
        ->assertSee('0 servers')
        ->assertSee('No servers in this cluster yet.')
        ->assertDontSee('No clusters yet');
    Livewire::test(ServerIndex::class)->assertSee('No Docker servers yet');
});

it('keeps the classic servers page when the cluster stack is disabled', function () {
    config()->set('constants.sentinel.host_enabled', false);
    $cluster = CreateNodeCluster::run($this->team, $this->user, 'Hidden mesh');
    AssignNodeToCluster::run($cluster, Node::factory()->create(['team_id' => $this->team->id, 'name' => 'Hidden cluster server']));
    serverIndexDockerServer($this->team->id, 'Docker host one');

    Livewire::test(ServerIndex::class)
        ->assertDontSeeLivewire(ClusterGroups::class)
        ->assertSee('Docker host one')
        ->assertSee('New server')
        ->assertSee(route('server.create'), escape: false)
        ->assertDontSee('Hidden mesh')
        ->assertDontSee('Hidden cluster server')
        ->assertDontSee('Docker servers')
        ->assertDontSee('Cluster server')
        ->assertDontSee(route('node.onboarding'), escape: false);
});

it('asks whether to add a cluster server or a Docker server', function () {
    Livewire::test(ServerIndex::class)
        ->assertSeeHtml('data-testid="add-server-choice"')
        ->assertSeeInOrder(['Add server', 'Cluster server', 'Podman host managed by Sentinel', 'Docker server', 'Docker host managed over SSH'])
        ->assertSee(route('node.onboarding'), escape: false)
        ->assertSee(route('server.create'), escape: false)
        ->assertDontSee('New server');

    $this->get(route('node.onboarding'))->assertOk()->assertSee('Add cluster server');
});

it('hides admin-only server and cluster actions from team members', function () {
    $cluster = CreateNodeCluster::run($this->team, $this->user, 'Member mesh');
    AssignNodeToCluster::run($cluster, Node::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'Outdated server',
        'is_usable' => true,
        'sentinel_version' => '1.0.0',
    ]));
    $member = User::factory()->create();
    $member->teams()->attach($this->team->id, ['role' => 'member']);
    $this->actingAs($member);
    session(['currentTeam' => $this->team]);

    Livewire::test(ServerIndex::class)
        ->assertDontSee('Add server')
        ->assertDontSeeHtml('data-testid="add-server-choice"')
        ->assertDontSee(route('node.onboarding'), escape: false);
    Livewire::test(ClusterGroups::class)
        ->assertSee('Member mesh')
        ->assertSee('Outdated server')
        ->assertSee('Upgrade available')
        ->assertDontSee('New cluster')
        ->assertDontSee('Upgrade all')
        ->set('name', 'Sneaky cluster')
        ->call('createCluster')
        ->assertForbidden();

    expect(NodeCluster::query()->where('name', 'Sneaky cluster')->exists())->toBeFalse();
});

it('marks Servers as the active sidebar item on cluster pages', function (string $routeName, string $parameter) {
    $cluster = CreateNodeCluster::run($this->team, $this->user, 'Sidebar mesh');
    $node = Node::factory()->create(['team_id' => $this->team->id, 'name' => 'Sidebar server']);
    AssignNodeToCluster::run($cluster, $node);
    $uuid = $parameter === 'cluster_uuid' ? $cluster->uuid : $node->uuid;

    $content = $this->get(route($routeName, [$parameter => $uuid]))->assertOk()->getContent();

    expect($content)
        ->toMatch('/<a title="Servers"[^>]*class="menu-item menu-item-active"/')
        ->not->toContain('title="Clusters"');
})->with([
    'cluster' => ['node-cluster.show', 'cluster_uuid'],
    'cluster servers' => ['node-cluster.nodes', 'cluster_uuid'],
    'cluster server' => ['node.show', 'node_uuid'],
    'cluster server applications' => ['node.workloads', 'node_uuid'],
]);

it('serves cluster pages under the new paths', function () {
    expect(route('node-cluster.show', ['cluster_uuid' => 'c1'], false))->toBe('/cluster/c1')
        ->and(route('node-cluster.nodes', ['cluster_uuid' => 'c1'], false))->toBe('/cluster/c1/servers')
        ->and(route('node-cluster.firewall', ['cluster_uuid' => 'c1'], false))->toBe('/cluster/c1/firewall')
        ->and(route('node-cluster.advanced', ['cluster_uuid' => 'c1'], false))->toBe('/cluster/c1/advanced')
        ->and(route('node-cluster.delete', ['cluster_uuid' => 'c1'], false))->toBe('/cluster/c1/danger')
        ->and(route('node.show', ['node_uuid' => 'n1'], false))->toBe('/cluster-server/n1')
        ->and(route('node.workloads', ['node_uuid' => 'n1'], false))->toBe('/cluster-server/n1/applications')
        ->and(route('node.containers', ['node_uuid' => 'n1'], false))->toBe('/cluster-server/n1/containers')
        ->and(route('node.sentinel', ['node_uuid' => 'n1'], false))->toBe('/cluster-server/n1/sentinel')
        ->and(route('node.internal-dns', ['node_uuid' => 'n1'], false))->toBe('/cluster-server/n1/internal-dns')
        ->and(route('node.logs', ['node_uuid' => 'n1'], false))->toBe('/cluster-server/n1/logs')
        ->and(route('node.command', ['node_uuid' => 'n1'], false))->toBe('/cluster-server/n1/terminal')
        ->and(route('node.onboarding', [], false))->toBe('/servers/new/cluster-server')
        ->and(route('settings.node-trust', [], false))->toBe('/settings/cluster-trust')
        ->and(Route::has('node-cluster.index'))->toBeFalse();
});

it('permanently redirects old cluster paths to the new paths', function (string $oldPath, string $newPath) {
    $this->get($oldPath)->assertStatus(301)->assertRedirect($newPath);
})->with([
    ['/node-clusters', '/servers'],
    ['/node-clusters/new-node', '/servers/new/cluster-server'],
    ['/node-clusters/abc123', '/cluster/abc123'],
    ['/node-clusters/abc123/nodes', '/cluster/abc123/servers'],
    ['/node-clusters/abc123/firewall', '/cluster/abc123/firewall'],
    ['/node-clusters/abc123/advanced', '/cluster/abc123/advanced'],
    ['/node-clusters/abc123/danger', '/cluster/abc123/danger'],
    ['/node/xyz789', '/cluster-server/xyz789'],
    ['/node/xyz789/workloads', '/cluster-server/xyz789/applications'],
    ['/node/xyz789/containers', '/cluster-server/xyz789/containers'],
    ['/node/xyz789/sentinel', '/cluster-server/xyz789/sentinel'],
    ['/node/xyz789/internal-dns', '/cluster-server/xyz789/internal-dns'],
    ['/node/xyz789/logs', '/cluster-server/xyz789/logs'],
    ['/node/xyz789/terminal', '/cluster-server/xyz789/terminal'],
    ['/settings/node-trust', '/settings/cluster-trust'],
]);
