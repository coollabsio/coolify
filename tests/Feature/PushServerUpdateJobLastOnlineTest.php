<?php

use App\Jobs\PushServerUpdateJob;
use App\Models\Server;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('database last_online_at is updated when status unchanged', function () {
    $team = Team::factory()->create();
    $database = StandalonePostgresql::factory()->create([
        'team_id' => $team->id,
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

    // last_online_at should be updated even though status didn't change
    expect($database->last_online_at->greaterThan($oldLastOnline))->toBeTrue();
    expect($database->status)->toBe('running:healthy');
});

test('database status is updated when container status changes', function () {
    $team = Team::factory()->create();
    $database = StandalonePostgresql::factory()->create([
        'team_id' => $team->id,
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
    $database = StandalonePostgresql::factory()->create([
        'team_id' => $team->id,
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
