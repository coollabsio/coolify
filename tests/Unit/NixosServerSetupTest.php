<?php

use App\Actions\Server\InstallDocker;

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
