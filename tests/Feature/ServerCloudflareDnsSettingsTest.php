<?php

use App\Livewire\Server\Advanced;
use App\Models\CloudProviderToken;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'ip' => '192.0.2.10',
    ]);
});

test('server advanced settings persist Cloudflare DNS configuration', function () {
    $token = CloudProviderToken::factory()->create([
        'team_id' => $this->team->id,
        'provider' => 'cloudflare',
        'name' => 'Production Cloudflare',
    ]);

    Livewire::test(Advanced::class, ['server_uuid' => $this->server->uuid])
        ->set('cloudflareDnsEnabled', true)
        ->set('cloudflareDnsTokenId', $token->id)
        ->set('cloudflareDnsProxied', false)
        ->call('submit')
        ->assertDispatched('success');

    $this->server->settings->refresh();

    expect($this->server->settings->cloudflare_dns_enabled)->toBeTrue()
        ->and($this->server->settings->cloudflare_dns_token_id)->toBe($token->id)
        ->and($this->server->settings->cloudflare_dns_proxied)->toBeFalse();
});

test('server advanced settings require a Cloudflare token when DNS management is enabled', function () {
    Livewire::test(Advanced::class, ['server_uuid' => $this->server->uuid])
        ->set('cloudflareDnsEnabled', true)
        ->set('cloudflareDnsTokenId', null)
        ->call('submit')
        ->assertDispatched('error');
});
