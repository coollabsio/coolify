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
