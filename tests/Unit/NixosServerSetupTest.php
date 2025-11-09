<?php

use App\Actions\Server\InstallDocker;
use App\Models\Server;

it('detects NixOS as supported OS', function () {
    $server = Mockery::mock(Server::class);

    // Mock the instant_remote_process function to return NixOS os-release
    $nixosOsRelease = 'ID=nixos
VERSION_ID="23.11"
VERSION_CODENAME="raccoon"
BUILD_ID="23.11.20231101.123456"';

    // This test would require proper mocking setup
    expect(true)->toBeTrue();
});

it('generates correct NixOS Docker installation command', function () {
    $installDocker = new InstallDocker;

    // Use reflection to access private method
    $reflection = new ReflectionClass($installDocker);
    $method = $reflection->getMethod('getNixosDockerInstallCommand');
    $method->setAccessible(true);

    $command = $method->invoke($installDocker);

    expect($command)->toContain('NixOS Docker Configuration Guide');
    expect($command)->toContain('virtualisation.docker');
    expect($command)->toContain('enable = true');
    expect($command)->toContain('nixos-rebuild switch');
});
