<?php

use App\Actions\Node\InspectNodeHost;
use App\Actions\Node\InstallSentinel;
use App\Actions\Node\PrepareNodeHost;
use App\Actions\Node\ReconcileNodeClusterNetwork;
use App\Actions\Node\ValidateNode;
use App\Actions\Sentinel\PingFluxConnection;
use App\Jobs\OnboardNodeJob;
use App\Livewire\Node\Onboarding;
use App\Livewire\NodeCluster\Index;
use App\Models\InstanceSettings;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\PrivateKey;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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
    $this->key = PrivateKey::factory()->create(['team_id' => $this->team->id]);
});

it('shows the node onboarding route and call to action', function () {
    $this->get(route('node.onboarding'))->assertOk()->assertSee('Connect your server');

    Livewire::test(Index::class)
        ->assertSee('Add Node')
        ->assertSee(route('node.onboarding'), escape: false);
});

it('connects a host and creates its required cluster before queueing installation', function () {
    Queue::fake();
    InspectNodeHost::shouldRun()->andReturn([
        'hostname' => 'worker-1', 'os' => 'Ubuntu 24.04', 'arch' => 'x86_64', 'cpus' => 4,
        'memory_bytes' => 8_000_000_000, 'package_manager' => 'apt-get', 'podman_installed' => false,
    ]);

    $component = Livewire::test(Onboarding::class)
        ->set('name', 'Worker one')->set('ip', '192.0.2.50')->set('privateKeyId', $this->key->id)
        ->call('connect')->assertHasNoErrors()->assertSet('step', 2)->assertSee('Will be installed')
        ->set('clusterMode', 'new')->set('clusterName', 'Production cluster')
        ->call('install')->assertHasNoErrors()->assertSet('step', 3);

    $node = Node::query()->where('uuid', $component->get('nodeUuid'))->firstOrFail();
    expect($node->cluster)->not->toBeNull()
        ->and($node->cluster->name)->toBe('Production cluster')
        ->and(data_get($node->metadata, 'onboarding.status'))->toBe('queued');
    Queue::assertPushed(OnboardNodeJob::class, fn (OnboardNodeJob $job) => $job->nodeId === $node->id);
});

it('does not permit a cluster from another team', function () {
    Queue::fake();
    $foreignUser = User::factory()->create();
    $foreignCluster = NodeCluster::factory()->create(['team_id' => $foreignUser->teams()->firstOrFail()->id]);
    InspectNodeHost::shouldRun()->andReturn([
        'hostname' => 'worker-2', 'os' => 'Ubuntu 24.04', 'arch' => 'x86_64', 'cpus' => 2,
        'memory_bytes' => 4_000_000_000, 'package_manager' => 'apt-get', 'podman_installed' => true,
    ]);

    Livewire::test(Onboarding::class)
        ->set('name', 'Worker two')->set('ip', '192.0.2.51')->set('privateKeyId', $this->key->id)
        ->call('connect')->set('clusterMode', 'existing')->set('clusterUuid', $foreignCluster->uuid)
        ->call('install')->assertHasErrors('clusterUuid');

    Queue::assertNothingPushed();
});

it('removes the draft node when the SSH inspection fails', function () {
    InspectNodeHost::shouldRun()->andThrow(new RuntimeException('Connection refused'));

    Livewire::test(Onboarding::class)
        ->set('name', 'Bad host')->set('ip', '192.0.2.52')->set('privateKeyId', $this->key->id)
        ->call('connect')->assertHasErrors('ip')->assertSet('step', 1);

    expect(Node::query()->where('ip', '192.0.2.52')->exists())->toBeFalse();
});

it('runs each installation stage and marks the node ready', function () {
    $cluster = NodeCluster::factory()->create(['team_id' => $this->team->id]);
    $node = Node::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->key->id,
        'node_cluster_id' => $cluster->id,
    ]);
    PrepareNodeHost::shouldRun()->andReturn('ready');
    InstallSentinel::shouldRun()->andReturn('installed');
    ValidateNode::shouldRun()->andReturn(true);
    PingFluxConnection::shouldRun()->andReturn(['ok' => true]);
    ReconcileNodeClusterNetwork::shouldRun()->andReturn([]);

    (new OnboardNodeJob($node->id, $this->user->id))->handle();

    expect(data_get($node->fresh()->metadata, 'onboarding.status'))->toBe('ready')
        ->and(data_get($node->fresh()->metadata, 'onboarding.step'))->toBe('ready');
});

it('restores installation progress after a page refresh', function () {
    $cluster = NodeCluster::factory()->create(['team_id' => $this->team->id]);
    $node = Node::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->key->id,
        'node_cluster_id' => $cluster->id,
        'metadata' => [
            'hostname' => 'worker-refresh',
            'onboarding' => ['status' => 'running', 'step' => 'installing', 'label' => 'Installing Node components'],
        ],
    ]);

    Livewire::withQueryParams(['node' => $node->uuid])
        ->test(Onboarding::class)
        ->assertSet('nodeUuid', $node->uuid)
        ->assertSet('step', 3)
        ->assertSee('Installing your Node')
        ->assertSee('Installing Node components');
});

it('does not restore another team node from the URL', function () {
    $foreignUser = User::factory()->create();
    $foreignNode = Node::factory()->create([
        'team_id' => $foreignUser->teams()->firstOrFail()->id,
        'private_key_id' => $this->key->id,
    ]);

    expect(fn () => Livewire::withQueryParams(['node' => $foreignNode->uuid])->test(Onboarding::class))
        ->toThrow(ModelNotFoundException::class);
});

it('stores and displays technical details for the failed installation stage', function () {
    $cluster = NodeCluster::factory()->create(['team_id' => $this->team->id]);
    $node = Node::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->key->id,
        'node_cluster_id' => $cluster->id,
    ]);
    PrepareNodeHost::shouldRun()->andThrow(new RuntimeException('apt-get failed: package repository unavailable'));

    expect(fn () => (new OnboardNodeJob($node->id, $this->user->id))->handle())
        ->toThrow(RuntimeException::class);

    $onboarding = data_get($node->fresh()->metadata, 'onboarding');
    expect($onboarding['status'])->toBe('failed')
        ->and($onboarding['step'])->toBe('preparing')
        ->and($onboarding['technical_error'])->toContain('apt-get failed');

    Livewire::withQueryParams(['node' => $node->uuid])
        ->test(Onboarding::class)
        ->assertSet('step', 3)
        ->assertSee('Technical details')
        ->assertSee('Preparing server')
        ->assertSee('apt-get failed: package repository unavailable');
});

it('prepares the Docker-compatible socket required by Sentinel', function () {
    expect(PrepareNodeHost::installationScript())
        ->toContain('systemctl enable --now podman.socket')
        ->toContain('ln -sfn /run/podman/podman.sock /var/run/docker.sock')
        ->toContain('test -S /var/run/docker.sock');
});

it('uses the reachable development gateway callback for a qemu node', function () {
    InspectNodeHost::shouldRun()->andReturn([
        'hostname' => 'worker-qemu', 'os' => 'Ubuntu 24.04', 'arch' => 'x86_64', 'cpus' => 2,
        'memory_bytes' => 4_000_000_000, 'package_manager' => 'apt-get', 'podman_installed' => false,
    ]);

    $component = Livewire::test(Onboarding::class)
        ->set('name', 'QEMU onboarding')
        ->set('ip', '192.168.122.52')
        ->set('privateKeyId', $this->key->id)
        ->set('coolifyUrl', 'https://devserver.example.test:8000')
        ->call('connect')
        ->assertHasNoErrors();

    $node = Node::query()->where('uuid', $component->get('nodeUuid'))->firstOrFail();
    expect($node->sentinel_url)->toBe('http://192.168.122.1:8000');
});
