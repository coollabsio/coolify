<?php

use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('enables Sentinel only for existing active regular servers', function () {
    $user = User::factory()->create();
    $teamId = $user->teams()->first()->id;

    $regularServer = Server::factory()->create(['team_id' => $teamId]);
    $regularServer->settings->update([
        'is_sentinel_enabled' => false,
        'is_reachable' => true,
        'is_usable' => true,
    ]);

    $buildServer = Server::factory()->create(['team_id' => $teamId]);
    $buildServer->settings->update([
        'is_sentinel_enabled' => false,
        'is_build_server' => true,
        'is_reachable' => true,
        'is_usable' => true,
    ]);

    $unvalidatedServer = Server::factory()->create(['team_id' => $teamId]);
    $unvalidatedServer->settings->update([
        'is_sentinel_enabled' => false,
        'is_reachable' => false,
        'is_usable' => false,
    ]);

    $migration = require database_path('migrations/2026_09_08_202212_enable_sentinel_for_existing_regular_servers.php');
    $migration->up();

    expect((bool) $regularServer->settings->fresh()->is_sentinel_enabled)->toBeTrue()
        ->and((bool) $buildServer->settings->fresh()->is_sentinel_enabled)->toBeFalse()
        ->and((bool) $unvalidatedServer->settings->fresh()->is_sentinel_enabled)->toBeFalse();
});
