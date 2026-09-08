<?php

use App\Enums\ProxyTypes;
use App\Jobs\CheckTraefikVersionForServerJob;
use App\Livewire\Server\Proxy;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

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

it('clears stale outdated information before detecting the current version', function () {
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

    expect($server->detected_traefik_version)->toBe('3.6.23')
        ->and($server->traefik_outdated_info)->toBeNull();
});
