<?php

use App\Actions\Node\FetchContainers;
use App\Actions\Node\InstallSentinel;
use App\Actions\Node\RepairFluxTrust;
use App\Actions\Sentinel\PingFluxConnection;
use App\Actions\Sentinel\RenewFluxCertificate;
use App\Enums\NodeContainerManagementState;
use App\Livewire\Node\Show;
use App\Livewire\Server\Sentinel as LegacySentinel;
use App\Models\InstanceSettings;
use App\Models\Node;
use App\Models\Server;
use App\Models\Team;
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
    $team = $user->teams()->firstOrFail();
    session(['currentTeam' => $team]);
    $this->node = Node::factory()->create(['team_id' => $team->id]);
});

it('shows and runs node Sentinel controls in development', function () {
    InstallSentinel::partialMock()->shouldReceive('handle')->once()->with(Mockery::type(Node::class))->andReturn('');
    PingFluxConnection::partialMock()->shouldReceive('handle')->once()->andReturn([
        'sentinel_version' => 'main',
        'latency_ms' => 12,
    ]);
    RepairFluxTrust::partialMock()->shouldReceive('handle')->once()->andReturn('');

    Livewire::test(Show::class, ['node_uuid' => $this->node->uuid])
        ->assertSet('section', 'general')
        ->assertSee($this->node->user.'@'.$this->node->ip.':'.$this->node->port)
        ->assertDontSee('{{ $node->ip }}', escape: false)
        ->assertDontSee('Update Sentinel');

    Livewire::test(Show::class, ['node_uuid' => $this->node->uuid, 'section' => 'sentinel'])
        ->assertSee('Connection')
        ->assertSee('Update Sentinel')
        ->assertSee('Troubleshooting')
        ->assertSee('Validate node')
        ->assertSee('Repair trust')
        ->assertDontSee('Renew certificate')
        ->assertDontSee('Refresh state')
        ->assertDontSee('Install or update')
        ->call('installSentinel')
        ->assertDispatched('success', 'Host Sentinel installed and started.')
        ->call('testFluxConnection')
        ->assertDispatched('success', 'Flux connection test succeeded. Sentinel main responded in 12 ms.')
        ->call('repairFluxTrust')
        ->assertDispatched('success', 'Sentinel Flux trust repaired.');
});

it('refreshes the node Flux connection state from cache', function () {
    $component = Livewire::test(Show::class, ['node_uuid' => $this->node->uuid, 'section' => 'sentinel'])
        ->assertSee('Disconnected');

    Cache::put($this->node->cacheKey(), [
        'status' => 'connected',
        'transport' => 'tls',
        'endpoint' => 'https://flux.example.com:7443',
        'last_heartbeat_at' => '2026-09-11T10:00:30Z',
    ]);

    $component->call('refreshFluxConnection')
        ->assertSee('Connected')
        ->assertDontSee('Disconnected')
        ->assertSee('TLS')
        ->assertSee('https://flux.example.com:7443')
        ->assertDispatched('info', 'Flux connection state refreshed.');
});

it('shows current Node capacity and resource pressure', function () {
    $this->node->update(['metadata' => [
        'cpu_usage_percent' => 96.25,
        'memory_bytes' => 8_000_000_000,
        'memory_used_bytes' => 4_000_000_000,
        'disk_total_bytes' => 10_000_000_000,
        'disk_available_bytes' => 2_500_000_000,
        'load_average' => ['one' => 1.25, 'five' => 1.0, 'fifteen' => 0.75],
    ]]);

    Livewire::test(Show::class, ['node_uuid' => $this->node->uuid])
        ->assertSee('CPU usage')
        ->assertSee('96.3%')
        ->assertSee('Memory usage')
        ->assertSee('50%')
        ->assertSee('Disk usage')
        ->assertSee('75%')
        ->assertSee('1.25 / 1.00 / 0.75')
        ->assertSee('Node pressure');
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

it('wraps Sentinel actions on narrow screens and keeps them text-only', function () {
    $view = file_get_contents(resource_path('views/livewire/node/partials/sentinel.blade.php'));

    expect($view)->toContain('flex flex-wrap items-center gap-2')
        ->and(substr_count($view, '<x-reicon'))->toBe(substr_count($view, '<x-reicon name="refresh"'));
});

it('hides raw revision and discovery state from the Node overview', function () {
    Livewire::test(Show::class, ['node_uuid' => $this->node->uuid])
        ->assertSee('Overview')
        ->assertSee('Not assigned')
        ->assertSee('Resource usage')
        ->assertDontSee('Revision')
        ->assertDontSee('Discovery')
        ->assertDontSee('Coolify endpoint');
});

it('shows validation output only while the Node is not ready', function () {
    $this->node->update(['is_usable' => false, 'validation_logs' => 'Podman is not installed.']);

    Livewire::test(Show::class, ['node_uuid' => $this->node->uuid])
        ->assertSee('Node is not ready')
        ->assertSee('Podman is not installed.');

    $this->node->update(['is_usable' => true]);

    Livewire::test(Show::class, ['node_uuid' => $this->node->uuid])
        ->assertDontSee('Podman is not installed.');
});

it('hides Sentinel management controls from team members', function () {
    $member = User::factory()->create();
    $member->teams()->attach($this->node->team_id, ['role' => 'member']);
    $this->actingAs($member);

    Livewire::test(Show::class, ['node_uuid' => $this->node->uuid, 'section' => 'sentinel'])
        ->assertSee('Connection')
        ->assertDontSee('Update Sentinel')
        ->assertDontSee('Repair trust')
        ->assertDontSee('Renew certificate')
        ->call('installSentinel')
        ->assertNotDispatched('success');
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

    Livewire::test(Show::class, ['node_uuid' => $this->node->uuid, 'section' => 'containers'])
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

it('lets only instance admins renew the instance-wide Flux certificate', function () {
    RenewFluxCertificate::partialMock()->shouldReceive('handle')->never();

    Livewire::test(Show::class, ['node_uuid' => $this->node->uuid, 'section' => 'sentinel'])
        ->call('renewFluxCertificate')
        ->assertNotDispatched('success');
});

it('renews the Flux certificate for a root team admin', function () {
    $rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);
    $user = User::factory()->create();
    $rootTeam->members()->attach($user->id, ['role' => 'admin']);
    $this->actingAs($user);
    session(['currentTeam' => $rootTeam]);
    $node = Node::factory()->create(['team_id' => 0]);
    RenewFluxCertificate::partialMock()->shouldReceive('handle')->once()->with(null, true);

    Livewire::test(Show::class, ['node_uuid' => $node->uuid, 'section' => 'sentinel'])
        ->assertSee('Renew certificate')
        ->call('renewFluxCertificate')
        ->assertDispatched('success', 'Flux TLS certificate renewed.');
});
