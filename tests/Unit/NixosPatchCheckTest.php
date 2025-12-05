<?php

use App\Actions\Server\CheckUpdates;

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
    expect($result['is_nixos'])->toBeTrue();
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
    expect($result['is_nixos'])->toBeTrue();
});
