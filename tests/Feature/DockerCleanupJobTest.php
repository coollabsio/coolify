<?php

use App\Jobs\DockerCleanupJob;
use App\Models\DockerCleanupExecution;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('persists the server id when creating an execution record', function () {
    $user = User::factory()->create();
    $team = $user->teams()->first();
    $server = Server::factory()->create(['team_id' => $team->id]);

    $execution = DockerCleanupExecution::create([
        'server_id' => $server->id,
    ]);

    expect($execution->server_id)->toBe($server->id);
    $this->assertDatabaseHas('docker_cleanup_executions', [
        'id' => $execution->id,
        'server_id' => $server->id,
    ]);
});

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

it('finishes the latest running execution when the job fails after a timeout', function () {
    $user = User::factory()->create();
    $team = $user->teams()->first();
    $server = Server::factory()->create(['team_id' => $team->id]);

    $olderExecution = DockerCleanupExecution::create([
        'server_id' => $server->id,
    ]);
    $timedOutExecution = DockerCleanupExecution::create([
        'server_id' => $server->id,
    ]);

    $job = new DockerCleanupJob($server);
    $job->failed(new RuntimeException('Docker cleanup job has timed out.'));

    expect($timedOutExecution->refresh()->status)->toBe('failed')
        ->and($timedOutExecution->message)->toBe('Docker cleanup job has timed out.')
        ->and($timedOutExecution->finished_at)->not->toBeNull()
        ->and($olderExecution->refresh()->status)->toBe('running')
        ->and($olderExecution->finished_at)->toBeNull();
});
