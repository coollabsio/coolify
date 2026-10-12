<?php

use App\Actions\Node\AssignNodeToCluster;
use App\Actions\Node\CreateNodeCluster;
use App\Actions\Node\FetchContainers;
use App\Actions\Node\InspectNodeHost;
use App\Actions\Node\RemoveNodeFromCluster;
use App\Actions\Node\UpdateNodeCluster;
use App\Actions\Node\ValidateNodeCallback;
use App\Jobs\OnboardNodeJob;
use App\Livewire\Node\Onboarding;
use App\Livewire\Node\Show as NodeShow;
use App\Livewire\NodeCluster\Show;
use App\Models\InstanceSettings;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\NodeWorkload;
use App\Models\PrivateKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    config()->set('app.env', 'local');
    config()->set('app.url', 'https://coolify.example.com');
    config()->set('constants.sentinel.host_enabled', true);
    $this->user = User::factory()->create();
    $this->team = $this->user->teams()->firstOrFail();
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

function updateClusterNetwork(NodeCluster $cluster, string $cidr, ?string $interface = null, ?int $port = null): NodeCluster
{
    return UpdateNodeCluster::run($cluster->refresh(), $cluster->name, $cluster->description, $cidr, $interface ?? $cluster->wireguard_interface, $port ?? $cluster->wireguard_port);
}

it('refuses a cidr change while a not yet activated cluster has nodes', function () {
    $cluster = CreateNodeCluster::run($this->team, $this->user, 'Pending');
    AssignNodeToCluster::run($cluster, Node::factory()->create(['team_id' => $this->team->id, 'ip' => '192.0.2.10']));

    expect(fn () => updateClusterNetwork($cluster, '10.30.0.0/24'))->toThrow(DomainException::class, 'Remove all servers');

    Livewire::test(Show::class, ['cluster_uuid' => $cluster->uuid])
        ->set('cidr', '10.30.0.0/24')
        ->call('saveSettings')
        ->assertHasErrors('cidr');
    expect($cluster->refresh()->cidr)->toBe('10.240.0.0/24');
});

it('allows a cidr change on an empty pending cluster', function () {
    $cluster = CreateNodeCluster::run($this->team, $this->user, 'Empty');

    expect(updateClusterNetwork($cluster, '10.30.0.0/24')->cidr)->toBe('10.30.0.0/24');
});

it('refuses wireguard interface and port changes once the network was activated', function (array $change) {
    $cluster = CreateNodeCluster::run($this->team, $this->user, 'Active');
    $cluster->update(['network_status' => 'active']);

    expect(fn () => updateClusterNetwork($cluster, $cluster->cidr, $change['interface'] ?? null, $change['port'] ?? null))
        ->toThrow(DomainException::class, 'cannot be changed');

    $component = Livewire::test(Show::class, ['cluster_uuid' => $cluster->uuid]);
    isset($change['interface']) ? $component->set('wireguardInterface', $change['interface']) : $component->set('wireguardPort', $change['port']);
    $component->call('saveSettings')->assertHasErrors();
    expect($cluster->refresh()->wireguard_interface)->toBe('coolify0')->and($cluster->wireguard_port)->toBe(51820);
})->with([
    'interface' => [['interface' => 'coolify1']],
    'port' => [['port' => 51821]],
]);

it('rejects assigning a node whose address is inside the cluster cidr', function () {
    $cluster = CreateNodeCluster::run($this->team, $this->user, 'Overlap', cidr: '10.50.0.0/24');
    $node = Node::factory()->create(['team_id' => $this->team->id, 'ip' => '10.50.0.7']);

    expect(fn () => AssignNodeToCluster::run($cluster, $node))->toThrow(DomainException::class, '10.50.0.7');
    expect($node->refresh()->node_cluster_id)->toBeNull();
});

it('rejects a cluster cidr that contains the Coolify host address', function () {
    InstanceSettings::query()->whereKey(0)->update(['public_ipv4' => '10.70.0.5']);

    expect(fn () => CreateNodeCluster::run($this->team, $this->user, 'Host', cidr: '10.70.0.0/24'))
        ->toThrow(DomainException::class, '10.70.0.5');

    $cluster = CreateNodeCluster::run($this->team, $this->user, 'Other', cidr: '10.71.0.0/24');
    expect(fn () => updateClusterNetwork($cluster, '10.70.0.0/24'))->toThrow(DomainException::class, '10.70.0.5');
});

it('skips the automatic cidr that contains the Coolify host address', function () {
    InstanceSettings::query()->whereKey(0)->update(['public_ipv4' => '10.240.0.5']);

    expect(CreateNodeCluster::run($this->team, $this->user, 'Auto')->cidr)->toBe('10.240.1.0/24');
});

it('refuses to remove a node that still hosts stopped workloads', function () {
    $cluster = CreateNodeCluster::run($this->team, $this->user, 'Workloads');
    $node = Node::factory()->create(['team_id' => $this->team->id, 'ip' => '192.0.2.10']);
    AssignNodeToCluster::run($cluster, $node);
    $node->workloads()->attach(NodeWorkload::factory()->create(['team_id' => $this->team->id]));

    expect(fn () => RemoveNodeFromCluster::run($cluster->refresh(), $node->refresh(), $this->user))
        ->toThrow(DomainException::class, 'Move or delete');
    expect($node->refresh()->node_cluster_id)->toBe($cluster->id)
        ->and($node->workloads()->count())->toBe(1);
});

describe('onboarding', function () {
    beforeEach(function () {
        Queue::fake();
        $this->key = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    });

    it('locks the onboarding node uuid', function () {
        $node = Node::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $this->key->id, 'metadata' => ['onboarding' => ['status' => 'review']]]);

        expect(fn () => Livewire::test(Onboarding::class)->set('nodeUuid', $node->uuid))
            ->toThrow(CannotUpdateLockedPropertyException::class);
    });

    it('does not install a node that is not waiting for review', function () {
        $cluster = NodeCluster::factory()->create(['team_id' => $this->team->id]);
        $node = Node::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $this->key->id, 'node_cluster_id' => $cluster->id, 'metadata' => ['onboarding' => ['status' => 'ready']]]);

        Livewire::withQueryParams(['server' => $node->uuid])->test(Onboarding::class)
            ->set('clusterMode', 'existing')->set('clusterUuid', $cluster->uuid)
            ->call('install')->assertStatus(422);
        Queue::assertNotPushed(OnboardNodeJob::class);
    });

    it('only retries a failed installation', function () {
        $cluster = NodeCluster::factory()->create(['team_id' => $this->team->id]);
        $node = Node::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $this->key->id, 'node_cluster_id' => $cluster->id, 'metadata' => ['onboarding' => ['status' => 'running']]]);

        Livewire::withQueryParams(['server' => $node->uuid])->test(Onboarding::class)->call('retry')->assertStatus(422);
        Queue::assertNotPushed(OnboardNodeJob::class);
    });

    it('does not create a new cluster for a node that already belongs to one', function () {
        $cluster = NodeCluster::factory()->create(['team_id' => $this->team->id]);
        $node = Node::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $this->key->id, 'node_cluster_id' => $cluster->id, 'metadata' => ['onboarding' => ['status' => 'review']]]);
        $component = Livewire::withQueryParams(['server' => $node->uuid])->test(Onboarding::class);

        $component->set('clusterMode', 'new')->set('clusterName', 'Second')->call('install')->assertStatus(422);
        expect(NodeCluster::query()->count())->toBe(1);
        Queue::assertNotPushed(OnboardNodeJob::class);
    });

    it('does not leave a new cluster behind when the node cannot join it', function () {
        ValidateNodeCallback::shouldRun()->andReturn('ok');
        InspectNodeHost::shouldRun()->andReturn(['hostname' => 'worker']);

        Livewire::test(Onboarding::class)
            ->set('name', 'Worker')->set('ip', '10.240.0.9')->set('privateKeyId', $this->key->id)
            ->call('connect')->assertSet('step', 2)
            ->set('clusterMode', 'new')->set('clusterName', 'Fresh')
            ->call('install')->assertHasErrors('clusterName')->assertSet('step', 2);

        expect(NodeCluster::query()->count())->toBe(0);
        Queue::assertNotPushed(OnboardNodeJob::class);
    });
});

it('requires update permission to refresh node containers', function () {
    $this->team->members()->updateExistingPivot($this->user->id, ['role' => 'member']);
    $node = Node::factory()->create(['team_id' => $this->team->id]);
    FetchContainers::shouldRun()->never();

    Livewire::test(NodeShow::class, ['node_uuid' => $node->uuid, 'section' => 'containers'])
        ->call('refreshContainers')
        ->assertNotDispatched('success');
});
