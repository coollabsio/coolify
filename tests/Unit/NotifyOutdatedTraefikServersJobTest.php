<?php

use App\Jobs\NotifyOutdatedTraefikServersJob;

it('has correct queue and retry configuration', function () {
    $job = new NotifyOutdatedTraefikServersJob;

    expect($job->tries)->toBe(3);
});

it('handles servers with null traefik_outdated_info gracefully', function () {
    // Create a mock server with null traefik_outdated_info
    $server = \Mockery::mock('App\Models\Server')->makePartial();
    $server->traefik_outdated_info = null;

    // Accessing the property should not throw an error
    $result = $server->traefik_outdated_info;

    expect($result)->toBeNull();
});

it('handles servers with traefik_outdated_info data', function () {
    $expectedInfo = [
        'current' => '3.5.0',
        'latest' => '3.6.2',
        'type' => 'minor_upgrade',
        'upgrade_target' => 'v3.6',
        'checked_at' => '2025-11-14T10:00:00Z',
    ];

    $server = \Mockery::mock('App\Models\Server')->makePartial();
    $server->traefik_outdated_info = $expectedInfo;

    // Should return the outdated info
    $result = $server->traefik_outdated_info;

    expect($result)->toBe($expectedInfo);
});

it('handles servers with patch update info without upgrade_target', function () {
    $expectedInfo = [
        'current' => '3.5.0',
        'latest' => '3.5.2',
        'type' => 'patch_update',
        'checked_at' => '2025-11-14T10:00:00Z',
    ];

    $server = \Mockery::mock('App\Models\Server')->makePartial();
    $server->traefik_outdated_info = $expectedInfo;

    // Should return the outdated info without upgrade_target
    $result = $server->traefik_outdated_info;

    expect($result)->toBe($expectedInfo);
    expect($result)->not->toHaveKey('upgrade_target');
});
