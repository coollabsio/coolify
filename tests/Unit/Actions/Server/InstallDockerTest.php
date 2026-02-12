<?php

use App\Actions\Server\InstallDocker;

it('uses Debian codename fallbacks in apt repository command', function () {
    $action = new InstallDocker;

    $reflection = new ReflectionClass(InstallDocker::class);
    $dockerVersionProperty = $reflection->getProperty('dockerVersion');
    $dockerVersionProperty->setAccessible(true);
    $dockerVersionProperty->setValue($action, '27.3');

    $method = $reflection->getMethod('getDebianDockerInstallCommand');
    $method->setAccessible(true);

    $command = $method->invoke($action);

    expect($command)->toContain('${VERSION_CODENAME:-${DEBIAN_CODENAME:-$UBUNTU_CODENAME}}')
        ->and($command)->toContain('https://download.docker.com/linux/${ID}')
        ->and($command)->toContain('docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin');
});
