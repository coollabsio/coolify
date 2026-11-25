<?php

use App\Models\Application;
use App\Models\Server;

beforeEach(function () {
    // Mock server
    $this->server = Mockery::mock(Server::class);
    $this->server->shouldReceive('isFunctional')->andReturn(true);
    $this->server->shouldReceive('isSwarm')->andReturn(false);
    $this->server->shouldReceive('applications')->andReturn(collect());

    // Mock application
    $this->application = Mockery::mock(Application::class);
    $this->application->shouldReceive('getAttribute')->with('id')->andReturn(1);
    $this->application->shouldReceive('getAttribute')->with('name')->andReturn('test-app');
    $this->application->shouldReceive('getAttribute')->with('restart_count')->andReturn(0);
    $this->application->shouldReceive('getAttribute')->with('uuid')->andReturn('test-uuid');
    $this->application->shouldReceive('getAttribute')->with('environment')->andReturn(null);
});

it('extracts restart count from container data', function () {
    $containerData = [
        'RestartCount' => 5,
        'State' => [
            'Status' => 'running',
            'Health' => ['Status' => 'healthy'],
        ],
        'Config' => [
            'Labels' => [
                'coolify.applicationId' => '1',
                'com.docker.compose.service' => 'web',
            ],
        ],
    ];

    $restartCount = data_get($containerData, 'RestartCount', 0);

    expect($restartCount)->toBe(5);
});

it('defaults to zero when restart count is missing', function () {
    $containerData = [
        'State' => [
            'Status' => 'running',
        ],
        'Config' => [
            'Labels' => [],
        ],
    ];

    $restartCount = data_get($containerData, 'RestartCount', 0);

    expect($restartCount)->toBe(0);
});

it('detects restart count increase', function () {
    $previousRestartCount = 2;
    $currentRestartCount = 5;

    expect($currentRestartCount)->toBeGreaterThan($previousRestartCount);
});

it('identifies maximum restart count from multiple containers', function () {
    $containerRestartCounts = collect([
        'web' => 3,
        'worker' => 5,
        'scheduler' => 1,
    ]);

    $maxRestartCount = $containerRestartCounts->max();

    expect($maxRestartCount)->toBe(5);
});

it('handles empty restart counts collection', function () {
    $containerRestartCounts = collect([]);

    $maxRestartCount = $containerRestartCounts->max() ?? 0;

    expect($maxRestartCount)->toBe(0);
});
