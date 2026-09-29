<?php

use App\Enums\ProxyTypes;
use App\Events\ProxyStatusChangedUI;
use App\Jobs\CheckTraefikVersionForServerJob;
use App\Jobs\CheckTraefikVersionJob;
use App\Livewire\Server\Proxy;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows patch and minor upgrade warnings on the first proxy render', function () {
    Cache::put('coolify:versions:all', [
        'traefik' => [
            'v3.7' => '3.7.13',
            'v3.6' => '3.6.25',
        ],
    ]);

    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);
    session(['currentTeam' => $team]);
    $this->actingAs($user);

    $server = Server::factory()->create([
        'team_id' => $team->id,
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value, 'status' => 'running'],
        'detected_traefik_version' => '3.6.1',
        'traefik_outdated_info' => [
            'current' => '3.6.1',
            'latest' => '3.7.13',
            'type' => 'minor_upgrade',
            'upgrade_target' => 'v3.7',
        ],
    ]);

    Livewire::test(Proxy::class, ['server' => $server])
        ->assertSee('Traefik patch update available')
        ->assertSee('v3.6.25')
        ->assertSee('New Traefik minor version available')
        ->assertSee('v3.7.13');
});

it('shows a warning from the saved image before version detection finishes', function () {
    Cache::put('coolify:versions:all', [
        'traefik' => ['v3.7' => '3.7.13', 'v3.6' => '3.6.25'],
    ]);

    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);
    session(['currentTeam' => $team]);
    $this->actingAs($user);

    $server = Server::factory()->create([
        'team_id' => $team->id,
        'proxy' => [
            'type' => ProxyTypes::TRAEFIK->value,
            'status' => 'running',
            'last_saved_proxy_configuration' => "services:\n  traefik:\n    image: 'traefik:v3.6.5'",
        ],
        'detected_traefik_version' => null,
    ]);

    Livewire::test(Proxy::class, ['server' => $server])
        ->assertSee('Configured image')
        ->assertSee('v3.6.5')
        ->assertSee('v3.6.25')
        ->assertSee('v3.7.13');
});

it('does not treat an unapplied image as the running Traefik version', function () {
    $server = Server::factory()->make([
        'proxy' => [
            'type' => ProxyTypes::TRAEFIK->value,
            'status' => 'running',
            'last_saved_settings' => 'new',
            'last_applied_settings' => 'old',
            'last_saved_proxy_configuration' => "services:\n  traefik:\n    image: traefik:v3.6.5",
        ],
        'detected_traefik_version' => null,
    ]);

    $component = new Proxy;
    $component->server = $server;

    expect($component->getTraefikVersionForWarningProperty())->toBeNull();
});

it('ignores stale minor upgrade information for the detected Traefik version', function () {
    Cache::put('coolify:versions:all', [
        'traefik' => [
            'v3.7' => '3.7.8',
            'v3.6' => '3.6.23',
        ],
    ]);

    $server = Server::factory()->make([
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value],
        'detected_traefik_version' => '3.7.8',
        'traefik_outdated_info' => [
            'current' => '3.6.23',
            'latest' => '3.7.8',
            'type' => 'minor_upgrade',
            'upgrade_target' => 'v3.7',
        ],
    ]);

    $component = new Proxy;
    $component->server = $server;

    expect($component->getNewerTraefikBranchAvailableProperty())->toBeNull();
});

it('does not offer the Traefik branch already configured on a running proxy', function () {
    Cache::put('coolify:versions:all', [
        'traefik' => [
            'v3.7' => '3.7.8',
            'v3.6' => '3.6.23',
        ],
    ]);

    $server = Server::factory()->make([
        'proxy' => [
            'type' => ProxyTypes::TRAEFIK->value,
            'status' => 'running',
        ],
        'detected_traefik_version' => '3.6.23',
        'traefik_outdated_info' => [
            'current' => '3.6.23',
            'latest' => '3.7.8',
            'type' => 'minor_upgrade',
            'upgrade_target' => 'v3.7',
        ],
    ]);

    $component = new Proxy;
    $component->server = $server;
    $component->proxySettings = <<<'YAML'
services:
  traefik:
    image: 'traefik:v3.7'
YAML;

    expect($component->getNewerTraefikBranchAvailableProperty())->toBeNull();
});

it('still offers a newer Traefik branch than the configured image', function () {
    Cache::put('coolify:versions:all', [
        'traefik' => [
            'v3.7' => '3.7.8',
            'v3.6' => '3.6.23',
        ],
    ]);

    $server = Server::factory()->make([
        'proxy' => [
            'type' => ProxyTypes::TRAEFIK->value,
            'status' => 'running',
        ],
        'detected_traefik_version' => '3.6.23',
    ]);

    $component = new Proxy;
    $component->server = $server;
    $component->proxySettings = 'services:'.PHP_EOL.'  traefik:'.PHP_EOL.'    image: traefik:v3.6';

    expect($component->getNewerTraefikBranchAvailableProperty())->toBe('v3.7');
});

it('clears the stale minor warning after the configured branch is applied', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'proxy' => [
            'type' => ProxyTypes::TRAEFIK->value,
            'status' => 'running',
            'last_saved_proxy_configuration' => <<<'YAML'
services:
  traefik:
    image: traefik:v3.7
YAML,
        ],
        'detected_traefik_version' => '3.6.23',
        'traefik_outdated_info' => [
            'current' => '3.6.23',
            'latest' => '3.7.8',
            'type' => 'minor_upgrade',
            'upgrade_target' => 'v3.7',
        ],
    ]);

    $component = new Proxy;
    $component->server = $server;
    $component->mount();
    $component->loadProxyConfiguration();

    expect($server->refresh()->traefik_outdated_info)->toBeNull();
});

it('preserves a newer Traefik warning stored after the warning was inspected', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'proxy' => [
            'type' => ProxyTypes::TRAEFIK->value,
            'status' => 'running',
        ],
        'detected_traefik_version' => '3.6.23',
        'traefik_outdated_info' => [
            'current' => '3.6.23',
            'latest' => '3.7.8',
            'type' => 'minor_upgrade',
            'upgrade_target' => 'v3.7',
        ],
    ]);

    $component = new Proxy;
    $component->server = $server;
    $component->proxySettings = 'services:'.PHP_EOL.'  traefik:'.PHP_EOL.'    image: traefik:v3.7';

    $newerWarning = [
        'current' => '3.7.8',
        'latest' => '3.8.1',
        'type' => 'minor_upgrade',
        'upgrade_target' => 'v3.8',
    ];
    Server::query()->whereKey($server->id)->update(['traefik_outdated_info' => $newerWarning]);

    $method = new ReflectionMethod($component, 'clearAppliedTraefikBranchWarning');
    $method->invoke($component);

    expect($server->refresh()->traefik_outdated_info)->toBe($newerWarning);
});

it('does not mark stale Traefik outdated information as a current warning', function () {
    $server = Server::factory()->make([
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value],
        'detected_traefik_version' => '3.7.8',
        'traefik_outdated_info' => [
            'current' => '3.6.23',
            'latest' => '3.7.8',
            'type' => 'minor_upgrade',
            'upgrade_target' => 'v3.7',
        ],
    ]);

    expect($server->hasCurrentTraefikOutdatedInfo())->toBeFalse();
});

it('marks matching Traefik outdated information as a current warning', function () {
    $server = Server::factory()->make([
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value],
        'detected_traefik_version' => 'v3.6.23',
        'traefik_outdated_info' => [
            'current' => '3.6.23',
            'latest' => '3.7.8',
            'type' => 'minor_upgrade',
            'upgrade_target' => 'v3.7',
        ],
    ]);

    expect($server->hasCurrentTraefikOutdatedInfo())->toBeTrue();
});

it('clears stale Traefik version state before detecting the current version', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'detected_traefik_version' => '3.6.23',
        'traefik_outdated_info' => [
            'current' => '3.6.23',
            'latest' => '3.7.8',
            'type' => 'minor_upgrade',
            'upgrade_target' => 'v3.7',
        ],
    ]);

    $job = new CheckTraefikVersionForServerJob($server, ['v3.7' => '3.7.8']);
    $method = new ReflectionMethod($job, 'clearOutdatedInfo');
    $method->invoke($job);

    $server->refresh();

    expect($server->detected_traefik_version)->toBeNull()
        ->and($server->traefik_outdated_info)->toBeNull();
});

it('clears Traefik version state when the proxy changes', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'proxy' => [
            'type' => ProxyTypes::TRAEFIK->value,
            'status' => 'running',
        ],
        'detected_traefik_version' => '3.6.23',
        'traefik_outdated_info' => [
            'current' => '3.6.23',
            'latest' => '3.7.8',
            'type' => 'minor_upgrade',
        ],
    ]);

    $server->changeProxy(ProxyTypes::NONE->value);

    expect($server->refresh()->detected_traefik_version)->toBeNull()
        ->and($server->traefik_outdated_info)->toBeNull();
});

it('cleans stale Traefik version state while selecting servers to check', function () {
    Bus::fake();
    Cache::put('coolify:versions:all', [
        'traefik' => ['v3.7' => '3.7.8'],
    ]);

    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'proxy' => [
            'type' => ProxyTypes::NONE->value,
            'status' => 'exited',
        ],
        'detected_traefik_version' => '3.6.23',
        'traefik_outdated_info' => [
            'current' => '3.6.23',
            'latest' => '3.7.8',
            'type' => 'minor_upgrade',
        ],
    ]);

    (new CheckTraefikVersionJob)->handle();

    expect($server->refresh()->detected_traefik_version)->toBeNull()
        ->and($server->traefik_outdated_info)->toBeNull();
});

it('does not inspect a server after its Traefik proxy has been disabled', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'proxy' => [
            'type' => ProxyTypes::NONE->value,
            'status' => 'exited',
        ],
        'detected_traefik_version' => '3.6.23',
        'traefik_outdated_info' => [
            'current' => '3.6.23',
            'latest' => '3.7.8',
            'type' => 'minor_upgrade',
        ],
    ]);
    Event::fake();

    (new CheckTraefikVersionForServerJob($server, ['v3.7' => '3.7.8']))->handle();

    expect($server->refresh()->detected_traefik_version)->toBeNull()
        ->and($server->traefik_outdated_info)->toBeNull();

    Event::assertNotDispatched(ProxyStatusChangedUI::class);
});
