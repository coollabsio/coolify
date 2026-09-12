<?php

use App\Actions\Sentinel\PingFluxConnection;
use App\Actions\Sentinel\RenewFluxCertificate;
use App\Actions\Server\InstallSentinelHost;
use App\Actions\Server\RepairSentinelFluxTrust;
use App\Livewire\Server\Sentinel;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    $user = User::factory()->create();
    $this->actingAs($user);
    $this->server = Server::factory()->create([
        'team_id' => $user->teams()->firstOrFail()->id,
        'mode' => 'node-worker',
    ]);
});

it('shows and runs the host installer in the gated development environment', function () {
    config()->set('app.env', 'local');
    config()->set('constants.sentinel.host_enabled', true);
    InstallSentinelHost::partialMock()->shouldReceive('handle')->once()->with(Mockery::type(Server::class))->andReturn('');

    Livewire::test(Sentinel::class, ['server' => $this->server])
        ->assertSee('Install host Sentinel')
        ->assertSee('local node development')
        ->call('installHostSentinel')
        ->assertDispatched('success', 'Host Sentinel installed and started.');
});

it('hides and blocks the host installer outside development', function () {
    config()->set('app.env', 'production');
    config()->set('constants.sentinel.host_enabled', true);
    InstallSentinelHost::partialMock()->shouldReceive('handle')->never();

    Livewire::test(Sentinel::class, ['server' => $this->server])
        ->assertDontSee('Install host Sentinel')
        ->call('installHostSentinel')
        ->assertNotFound();
});

it('hides the host installer when its gate is disabled', function () {
    config()->set('app.env', 'local');
    config()->set('constants.sentinel.host_enabled', false);

    Livewire::test(Sentinel::class, ['server' => $this->server])
        ->assertDontSee('Install host Sentinel');
});

it('hides and blocks host-native Sentinel controls for legacy servers', function () {
    config()->set('app.env', 'local');
    config()->set('constants.sentinel.host_enabled', true);
    $this->server->update(['mode' => 'legacy']);

    Livewire::test(Sentinel::class, ['server' => $this->server->fresh()])
        ->assertDontSee('Flux control channel')
        ->assertDontSee('Install host Sentinel')
        ->call('installHostSentinel')
        ->assertNotFound();
});

it('shows the host installer button without an icon', function () {
    $view = file_get_contents(resource_path('views/livewire/server/sentinel.blade.php'));

    preg_match('/<x-forms\.button[^>]+wire:click="installHostSentinel"[^>]*>(?<content>.*?)<\/x-forms\.button>/s', $view, $matches);

    expect($matches['content'] ?? '')
        ->toContain('Install host Sentinel')
        ->not->toContain('<x-reicon');
});

it('shows the current Flux connection in development', function () {
    config()->set('app.env', 'local');
    config()->set('constants.sentinel.host_enabled', true);
    Cache::put("flux:connection:{$this->server->uuid}", [
        'transport' => 'plaintext',
        'endpoint' => 'http://flux:7443',
        'protocol_version' => 1,
        'connected_at' => '2026-09-11T10:00:00Z',
        'last_heartbeat_at' => '2026-09-11T10:00:30Z',
    ]);

    Livewire::test(Sentinel::class, ['server' => $this->server])
        ->assertSee('Flux control channel')
        ->assertSee('Connected')
        ->assertSee('Plaintext')
        ->assertSee('http://flux:7443')
        ->assertSee('2026-09-11T10:00:30Z')
        ->assertSee('Unencrypted control channel');
});

it('refreshes the Flux connection state from the cache', function () {
    config()->set('app.env', 'local');
    config()->set('constants.sentinel.host_enabled', true);

    $component = Livewire::test(Sentinel::class, ['server' => $this->server])
        ->assertSee('Disconnected');

    Cache::put("flux:connection:{$this->server->uuid}", [
        'transport' => 'tls',
        'endpoint' => 'https://flux.example.com:7443',
        'protocol_version' => 1,
        'connected_at' => '2026-09-11T10:00:00Z',
        'last_heartbeat_at' => '2026-09-11T10:00:30Z',
    ]);

    $component
        ->call('refreshFluxConnection')
        ->assertSee('Connected')
        ->assertSee('TLS')
        ->assertSee('https://flux.example.com:7443')
        ->assertDontSee('Unencrypted control channel');
});

it('keeps connection details visible during a short reconnect', function () {
    config()->set('app.env', 'local');
    config()->set('constants.sentinel.host_enabled', true);
    Cache::put("flux:connection:{$this->server->uuid}", [
        'status' => 'reconnecting',
        'transport' => 'tls',
        'endpoint' => 'https://flux.example.com:7443',
        'protocol_version' => 1,
        'connected_at' => '2026-09-11T10:00:00Z',
    ]);

    Livewire::test(Sentinel::class, ['server' => $this->server])
        ->assertSee('Reconnecting')
        ->assertSee('2026-09-11T10:00:00Z')
        ->call('refreshFluxConnection')
        ->assertDispatched('info', 'Flux connection state refreshed. Sentinel is reconnecting.');
});

it('shows a notification when connection state is refreshed', function () {
    config()->set('app.env', 'local');
    config()->set('constants.sentinel.host_enabled', true);

    Livewire::test(Sentinel::class, ['server' => $this->server])
        ->call('refreshFluxConnection')
        ->assertDispatched('info', 'Flux connection state refreshed. Sentinel is disconnected.');
});

it('tests the Flux connection through Sentinel', function () {
    config()->set('app.env', 'local');
    config()->set('constants.sentinel.host_enabled', true);
    Cache::put("flux:connection:{$this->server->uuid}", [
        'transport' => 'tls',
        'endpoint' => 'https://flux.example.com:7443',
        'protocol_version' => 1,
    ]);
    PingFluxConnection::partialMock()->shouldReceive('handle')->once()->andReturn([
        'command_id' => 'command-1',
        'nonce' => 'nonce-1',
        'sentinel_time_unix_ms' => 1_789_140_000_000,
        'sentinel_version' => 'main',
        'boot_id' => 'boot-1',
        'latency_ms' => 12,
    ]);

    Livewire::test(Sentinel::class, ['server' => $this->server])
        ->assertSee('Test connection')
        ->call('testFluxConnection')
        ->assertDispatched('success', 'Flux connection test succeeded. Sentinel main responded in 12 ms.')
        ->assertDontSee('Ping succeeded');
});

it('wraps Flux actions on narrow screens', function () {
    $view = file_get_contents(resource_path('views/livewire/server/sentinel.blade.php'));

    expect($view)->toContain('class="flex flex-wrap items-center gap-2"');
});

it('shows TLS trust state and runs the repair action', function () {
    config()->set('app.env', 'local');
    config()->set('constants.sentinel.host_enabled', true);
    Cache::put("flux:connection:{$this->server->uuid}", [
        'transport' => 'tls',
        'endpoint' => 'https://flux.example.com:7443',
        'protocol_version' => 1,
        'trust_bundle_version' => 3,
    ]);
    RepairSentinelFluxTrust::partialMock()->shouldReceive('handle')->once()->with(Mockery::type(Server::class))->andReturn('');

    Livewire::test(Sentinel::class, ['server' => $this->server])
        ->assertSee('Trust bundle')
        ->assertSee('Version 3')
        ->assertSee('Repair trust')
        ->call('repairFluxTrust')
        ->assertDispatched('success', 'Sentinel Flux trust repaired.');
});

it('authorizes and runs forced Flux certificate renewal', function () {
    config()->set('app.env', 'local');
    config()->set('constants.sentinel.host_enabled', true);
    RenewFluxCertificate::partialMock()->shouldReceive('handle')->once()->with(null, true)->andReturn(true);

    Livewire::test(Sentinel::class, ['server' => $this->server])
        ->assertSee('Renew certificate')
        ->call('renewFluxCertificate')
        ->assertDispatched('success', 'Flux TLS certificate renewed.');
});

it('blocks Flux connection data for a server from another team', function () {
    $otherUser = User::factory()->create();
    $otherServer = Server::factory()->create(['team_id' => $otherUser->teams()->firstOrFail()->id]);

    Livewire::test(Sentinel::class, ['server' => $otherServer])
        ->assertForbidden();
});
