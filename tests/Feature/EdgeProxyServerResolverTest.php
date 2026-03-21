<?php

use App\Enums\ProxyTypes;
use App\Models\Server;
use App\Models\User;
use App\Services\EdgeProxyRemotePortForwardService;
use App\Services\EdgeProxyRemoteRouteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('throws when route service resolves multiple master domain routers for a team', function () {
    $user = User::factory()->create();
    $team = $user->teams()->first();

    $firstServer = Server::factory()->create([
        'team_id' => $team->id,
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value],
    ]);
    $secondServer = Server::factory()->create([
        'team_id' => $team->id,
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value],
    ]);

    DB::table('server_settings')
        ->whereIn('server_id', [$firstServer->id, $secondServer->id])
        ->update(['is_master_domain_router_enabled' => true]);

    $manager = new class extends EdgeProxyRemoteRouteService
    {
        public function resolveForTeam(?int $teamId): ?Server
        {
            return $this->resolveEdgeProxyServerByTeamId($teamId);
        }
    };

    expect(fn () => $manager->resolveForTeam($team->id))
        ->toThrow(\RuntimeException::class, "Multiple master domain routers configured for team {$team->id}: server ids [{$firstServer->id}, {$secondServer->id}]");
});

it('throws when port-forward service resolves multiple master domain routers for a team', function () {
    $user = User::factory()->create();
    $team = $user->teams()->first();

    $firstServer = Server::factory()->create([
        'team_id' => $team->id,
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value],
    ]);
    $secondServer = Server::factory()->create([
        'team_id' => $team->id,
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value],
    ]);

    DB::table('server_settings')
        ->whereIn('server_id', [$firstServer->id, $secondServer->id])
        ->update(['is_master_domain_router_enabled' => true]);

    $manager = new class extends EdgeProxyRemotePortForwardService
    {
        public function resolveForTeam(?int $teamId): ?Server
        {
            return $this->resolveEdgeProxyServerByTeamId($teamId);
        }
    };

    expect(fn () => $manager->resolveForTeam($team->id))
        ->toThrow(\RuntimeException::class, "Multiple master domain routers configured for team {$team->id}: server ids [{$firstServer->id}, {$secondServer->id}]");
});
