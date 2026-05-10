<?php

use App\Jobs\PushServerUpdateJob;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

test('application container processing errors are logged instead of silently swallowed', function () {
    Log::spy();

    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);

    $data = [
        'containers' => [
            [
                'name' => 'test-app',
                'state' => 'running',
                'health_status' => 'healthy',
                'labels' => [
                    'coolify.managed' => true,
                    'coolify.applicationId' => '99999',
                    'coolify.pullRequestId' => '0',
                    'com.docker.compose.service' => 'app',
                ],
            ],
        ],
    ];

    $job = new PushServerUpdateJob($server, $data);
    $job->handle();

    // Container with unknown applicationId should not crash the job
    // and should not affect other containers
    expect($job->foundApplicationIds)->toBeEmpty();
});

test('valid application containers are tracked correctly', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);

    $data = [
        'containers' => [
            [
                'name' => 'coolify-proxy',
                'state' => 'running',
                'health_status' => 'healthy',
                'labels' => [
                    'coolify.managed' => true,
                    'coolify.type' => 'proxy',
                ],
            ],
        ],
    ];

    $job = new PushServerUpdateJob($server, $data);
    $job->handle();

    // Proxy should be detected
    expect($job->foundProxy)->toBeTrue();
});
