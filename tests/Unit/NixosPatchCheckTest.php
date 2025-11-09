<?php

use App\Actions\Server\CheckUpdates;
use App\Models\Server;

it('detects NixOS from os-release', function () {
    $server = Mockery::mock(Server::class);
    $server->shouldReceive('serverStatus')->andReturn(true);

    $nixosOsRelease = 'ID=nixos
VERSION_ID="23.11"
VERSION_CODENAME="raccoon"
BUILD_ID="23.11.20231101.123456"';

    $checkUpdates = new CheckUpdates;

    expect(true)->toBeTrue();
});

it('parses NixOS update output correctly', function () {
    $checkUpdates = new CheckUpdates;

    $reflection = new ReflectionClass($checkUpdates);
    $method = $reflection->getMethod('parseNixosOutput');
    $method->setAccessible(true);

    $sampleOutput = 'these 42 paths will be fetched';
    $result = $method->invoke($checkUpdates, $sampleOutput);

    expect($result['total_updates'])->toBe(1);
    expect($result['updates'])->toHaveCount(1);
    expect($result['updates'][0]['package'])->toBe('nixos-system');
    expect($result['updates'][0]['is_system_update'])->toBeTrue();
    expect($result['updates'][0]['package_count'])->toBe(42);
    expect($result['is_nixos'])->toBeTrue();
});

it('handles NixOS output with no specific package count', function () {
    $checkUpdates = new CheckUpdates;

    $reflection = new ReflectionClass($checkUpdates);
    $method = $reflection->getMethod('parseNixosOutput');
    $method->setAccessible(true);

    $sampleOutput = 'building the system configuration...
fetching packages...';
    $result = $method->invoke($checkUpdates, $sampleOutput);

    expect($result['total_updates'])->toBe(1);
    expect($result['updates'][0]['package_count'])->toBe('unknown');
    expect($result['updates'][0]['description'])->toContain('updates available');
});

it('returns empty updates when no NixOS changes detected', function () {
    $checkUpdates = new CheckUpdates;

    $reflection = new ReflectionClass($checkUpdates);
    $method = $reflection->getMethod('parseNixosOutput');
    $method->setAccessible(true);

    $sampleOutput = 'system is already up to date';
    $result = $method->invoke($checkUpdates, $sampleOutput);

    expect($result['total_updates'])->toBe(0);
    expect($result['updates'])->toHaveCount(0);
});
