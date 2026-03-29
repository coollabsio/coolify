<?php

use App\Jobs\DockerCleanupJob;
use App\Models\DockerCleanupExecution;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a failed execution record when server is not functional', function () {
    $user = User::factory()->create();
    $team = $user->teams()->first();
    $server = Server::factory()->create(['team_id' => $team->id]);

    // Make server not functional by setting is_reachable to false
    $server->settings->update(['is_reachable' => false]);

    $job = new DockerCleanupJob($server);
    $job->handle();

    $execution = DockerCleanupExecution::where('server_id', $server->id)->first();

    expect($execution)->not->toBeNull()
        ->and($execution->status)->toBe('failed')
        ->and($execution->message)->toContain('not functional')
        ->and($execution->finished_at)->not->toBeNull();
});

it('creates a failed execution record when server is force disabled', function () {
    $user = User::factory()->create();
    $team = $user->teams()->first();
    $server = Server::factory()->create(['team_id' => $team->id]);

    // Make server not functional by force disabling
    $server->settings->update([
        'is_reachable' => true,
        'is_usable' => true,
        'force_disabled' => true,
    ]);

    $job = new DockerCleanupJob($server);
    $job->handle();

    $execution = DockerCleanupExecution::where('server_id', $server->id)->first();

    expect($execution)->not->toBeNull()
        ->and($execution->status)->toBe('failed')
        ->and($execution->message)->toContain('not functional');
});
