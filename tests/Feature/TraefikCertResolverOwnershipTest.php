<?php

use App\Enums\ProxyTypes;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('uses public cert resolver when no master domain router is configured', function () {
    $user = User::factory()->create();
    $team = $user->teams()->first();

    $server = Server::factory()->create([
        'team_id' => $team->id,
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value],
    ]);

    expect(shouldUsePublicCertResolver($server))->toBeTrue();
});

it('uses public cert resolver only on the master domain router', function () {
    $user = User::factory()->create();
    $team = $user->teams()->first();

    $masterServer = Server::factory()->create([
        'team_id' => $team->id,
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value],
    ]);
    $otherServer = Server::factory()->create([
        'team_id' => $team->id,
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value],
    ]);

    DB::table('server_settings')
        ->where('server_id', $masterServer->id)
        ->update(['is_master_domain_router_enabled' => true]);

    expect(shouldUsePublicCertResolver($masterServer))->toBeTrue();
    expect(shouldUsePublicCertResolver($otherServer))->toBeFalse();
});
