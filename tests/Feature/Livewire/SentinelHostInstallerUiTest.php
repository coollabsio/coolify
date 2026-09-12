<?php

use App\Actions\Node\FetchContainers;
use App\Actions\Node\InstallSentinel;
use App\Actions\Node\RepairFluxTrust;
use App\Actions\Sentinel\PingFluxConnection;
use App\Enums\NodeContainerManagementState;
use App\Livewire\Node\Show;
use App\Livewire\Server\Sentinel as LegacySentinel;
use App\Models\InstanceSettings;
use App\Models\Node;
use App\Models\Server;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    config()->set('app.env', 'local');
    config()->set('constants.sentinel.host_enabled', true);
    $user = User::factory()->create();
    $this->actingAs($user);
    $this->node = Node::factory()->create(['team_id' => $user->teams()->firstOrFail()->id]);
});

it('shows and runs node Sentinel controls in development', function () {
    InstallSentinel::partialMock()->shouldReceive('handle')->once()->with(Mockery::type(Node::class))->andReturn('');
    PingFluxConnection::partialMock()->shouldReceive('handle')->once()->andReturn([
        'sentinel_version' => 'main',
        'latency_ms' => 12,
    ]);
    RepairFluxTrust::partialMock()->shouldReceive('handle')->once()->andReturn('');

    Livewire::test(Show::class, ['node_uuid' => $this->node->uuid])
        ->assertSee($this->node->user.'@'.$this->node->ip.':'.$this->node->port)
        ->assertDontSee('{{ $node->ip }}', escape: false)
        ->assertSee('Install or update')
        ->assertSee('Flux control channel')
        ->assertSee('Validate Podman')
        ->call('installSentinel')
        ->assertDispatched('success', 'Host Sentinel installed and started.')
        ->call('testFluxConnection')
        ->assertDispatched('success', 'Flux connection test succeeded. Sentinel main responded in 12 ms.')
        ->call('repairFluxTrust')
        ->assertDispatched('success', 'Sentinel Flux trust repaired.');
});

it('refreshes the node Flux connection state from cache', function () {
    $component = Livewire::test(Show::class, ['node_uuid' => $this->node->uuid])
        ->assertSee('Disconnected');

    Cache::put($this->node->cacheKey(), [
        'status' => 'connected',
        'transport' => 'tls',
        'endpoint' => 'https://flux.example.com:7443',
        'last_heartbeat_at' => '2026-09-11T10:00:30Z',
    ]);

    $component->call('refreshFluxConnection')
        ->assertSee('connected')
        ->assertSee('TLS')
        ->assertSee('https://flux.example.com:7443')
        ->assertDispatched('info', 'Flux connection state refreshed.');
});

it('does not expose node controls on a legacy server', function () {
    $server = Server::factory()->create(['team_id' => $this->node->team_id]);

    Livewire::test(LegacySentinel::class, ['server' => $server])
        ->assertDontSee('Flux control channel')
        ->assertDontSee('Install or update')
        ->assertSee('Development overrides');
});

it('blocks node pages outside development or for another team', function () {
    $otherUser = User::factory()->create();
    $otherNode = Node::factory()->create([
        'team_id' => $otherUser->teams()->firstOrFail()->id,
        'private_key_id' => $this->node->private_key_id,
    ]);

    expect(fn () => Livewire::test(Show::class, ['node_uuid' => $otherNode->uuid]))
        ->toThrow(ModelNotFoundException::class);

    config()->set('app.env', 'production');
    Livewire::test(Show::class, ['node_uuid' => $this->node->uuid])->assertNotFound();
});

it('wraps node actions on narrow screens and has no button icons', function () {
    $view = file_get_contents(resource_path('views/livewire/node/show.blade.php'));

    expect($view)->toContain('flex flex-wrap items-center gap-2')
        ->not->toContain('<x-reicon');
});

it('shows and refreshes the read-only Node container inventory', function () {
    $this->node->containers()->create([
        'runtime_id' => 'external-container-1',
        'name' => 'manual-nginx',
        'image' => 'docker.io/library/nginx:latest',
        'state' => 'running',
        'labels' => [],
        'management_state' => NodeContainerManagementState::EXTERNAL,
        'observed_at' => now(),
    ]);
    FetchContainers::partialMock()
        ->shouldReceive('handle')
        ->once()
        ->with(Mockery::type(Node::class))
        ->andReturn(1);

    Livewire::test(Show::class, ['node_uuid' => $this->node->uuid])
        ->assertSee('Containers')
        ->assertSee('manual-nginx')
        ->assertSee('nginx:latest')
        ->assertSee('External')
        ->assertDontSee('Start container')
        ->assertDontSee('Stop container')
        ->assertDontSee('Delete container')
        ->call('refreshContainers')
        ->assertDispatched('success', 'Container inventory refreshed. 1 container found.');
});
