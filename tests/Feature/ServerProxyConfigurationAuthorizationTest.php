<?php

use App\Enums\ProxyTypes;
use App\Livewire\Server\Proxy;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->proxyConfiguration = "services:\n  traefik:\n    image: traefik:v3.6\n    environment:\n      - CF_DNS_API_TOKEN=zzfix-cloudflare-secret\n    ports:\n      - '80:80'\n";
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'proxy' => [
            'type' => ProxyTypes::TRAEFIK->value,
            'status' => 'exited',
            'last_saved_proxy_configuration' => $this->proxyConfiguration,
        ],
    ]);
});

function actingAsProxyTeamUser(Team $team, string $role): User
{
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => $role]);
    test()->actingAs($user);
    session(['currentTeam' => $team]);

    return $user;
}

it('does not load the proxy configuration for a team member', function () {
    actingAsProxyTeamUser($this->team, 'member');

    $component = Livewire::test(Proxy::class, ['server' => $this->server])
        ->call('loadProxyConfiguration')
        ->assertOk()
        ->assertNotDispatched('error')
        ->assertSet('proxySettings', null);

    expect($component->html())->not->toContain('zzfix-cloudflare-secret');
});

it('loads the proxy configuration for a team admin', function () {
    actingAsProxyTeamUser($this->team, 'admin');

    Livewire::test(Proxy::class, ['server' => $this->server])
        ->call('loadProxyConfiguration')
        ->assertSet('proxySettings', $this->proxyConfiguration);
});
