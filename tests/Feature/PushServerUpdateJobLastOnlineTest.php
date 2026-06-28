<?php

use App\Jobs\PushServerUpdateJob;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('database last_online_at is not updated when status is unchanged', function () {
    $team = Team::factory()->create();
    $database = createPushUpdatePostgresql($team, [
        'status' => 'running:healthy',
        'last_online_at' => now()->subMinutes(5),
    ]);

    $server = $database->destination->server;

    $data = [
        'containers' => [
            [
                'name' => $database->uuid,
                'state' => 'running',
                'health_status' => 'healthy',
                'labels' => [
                    'coolify.managed' => 'true',
                    'coolify.type' => 'database',
                    'com.docker.compose.service' => $database->uuid,
                ],
            ],
        ],
    ];

    $oldLastOnline = $database->last_online_at;

    $job = new PushServerUpdateJob($server, $data);
    $job->handle();

    $database->refresh();

    expect((string) $database->last_online_at)->toBe((string) $oldLastOnline);
    expect($database->status)->toBe('running:healthy');
});

test('database status is updated when container status changes', function () {
    $team = Team::factory()->create();
    $database = createPushUpdatePostgresql($team, [
        'status' => 'exited',
    ]);

    $server = $database->destination->server;

    $data = [
        'containers' => [
            [
                'name' => $database->uuid,
                'state' => 'running',
                'health_status' => 'healthy',
                'labels' => [
                    'coolify.managed' => 'true',
                    'coolify.type' => 'database',
                    'com.docker.compose.service' => $database->uuid,
                ],
            ],
        ],
    ];

    $job = new PushServerUpdateJob($server, $data);
    $job->handle();

    $database->refresh();

    expect($database->status)->toBe('running:healthy');
});

test('database is not marked exited when containers list is empty', function () {
    $team = Team::factory()->create();
    $database = createPushUpdatePostgresql($team, [
        'status' => 'running:healthy',
    ]);

    $server = $database->destination->server;

    // Empty containers = Sentinel might have failed, should NOT mark as exited
    $data = [
        'containers' => [],
    ];

    $job = new PushServerUpdateJob($server, $data);
    $job->handle();

    $database->refresh();

    // Status should remain running, NOT be set to exited
    expect($database->status)->toBe('running:healthy');
});

function createPushUpdatePostgresql(Team $team, array $attributes = []): StandalonePostgresql
{
    $lastOnlineAt = $attributes['last_online_at'] ?? null;
    unset($attributes['last_online_at']);

    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->first()
        ?? StandaloneDocker::factory()->create(['server_id' => $server->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $database = StandalonePostgresql::create(array_merge([
        'uuid' => (string) str()->uuid(),
        'name' => 'postgres-'.str()->random(8),
        'postgres_password' => 'secret',
        'status' => 'exited',
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'environment_id' => $environment->id,
    ], $attributes));

    if ($lastOnlineAt !== null) {
        $database->forceFill(['last_online_at' => $lastOnlineAt])->saveQuietly();
    }

    return $database;
}
