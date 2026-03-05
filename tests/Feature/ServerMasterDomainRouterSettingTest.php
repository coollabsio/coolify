<?php

use App\Enums\ProxyTypes;
use App\Livewire\Server\Show;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('allows only one master domain router server per team', function () {
    $user = User::factory()->create();
    $team = $user->teams()->first();

    $firstServer = Server::factory()->create(['team_id' => $team->id]);
    $secondServer = Server::factory()->create(['team_id' => $team->id]);

    $firstServer->settings->update(['is_master_domain_router_enabled' => true]);

    expect($firstServer->settings->fresh()->is_master_domain_router_enabled)->toBeTrue()
        ->and($secondServer->settings->fresh()->is_master_domain_router_enabled)->toBeFalse();

    $secondServer->settings->update(['is_master_domain_router_enabled' => true]);

    expect($firstServer->settings->fresh()->is_master_domain_router_enabled)->toBeFalse()
        ->and($secondServer->settings->fresh()->is_master_domain_router_enabled)->toBeTrue();
});

it('does not disable master domain router setting on servers from other teams', function () {
    $teamOneUser = User::factory()->create();
    $teamTwoUser = User::factory()->create();

    $teamOneServer = Server::factory()->create(['team_id' => $teamOneUser->teams()->first()->id]);
    $teamTwoServer = Server::factory()->create(['team_id' => $teamTwoUser->teams()->first()->id]);

    $teamOneServer->settings->update(['is_master_domain_router_enabled' => true]);
    $teamTwoServer->settings->update(['is_master_domain_router_enabled' => true]);

    expect($teamOneServer->settings->fresh()->is_master_domain_router_enabled)->toBeTrue()
        ->and($teamTwoServer->settings->fresh()->is_master_domain_router_enabled)->toBeTrue();
});

it('locks master domain router toggle when another server in the same team is already selected', function () {
    $user = User::factory()->create();
    $team = $user->teams()->first();
    $this->actingAs($user);
    refreshSession($team);

    $masterServer = Server::factory()->create([
        'team_id' => $team->id,
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value],
    ]);
    $otherServer = Server::factory()->create([
        'team_id' => $team->id,
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value],
    ]);

    $masterServer->settings->update(['is_master_domain_router_enabled' => true]);

    Livewire::test(Show::class, ['server_uuid' => $otherServer->uuid])
        ->assertSet('isMasterDomainRouterLocked', true)
        ->assertSet('isMasterDomainRouterEnabled', false)
        ->set('isMasterDomainRouterEnabled', true)
        ->assertSet('isMasterDomainRouterEnabled', false);

    expect($otherServer->settings->fresh()->is_master_domain_router_enabled)->toBeFalse();
});
