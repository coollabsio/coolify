<?php

use App\Actions\Server\InstallDocker;

it('uses debian codename fallback for docker apt repo', function () {
    $action = new InstallDocker;

    $reflection = new ReflectionClass($action);

    $dockerVersionProperty = $reflection->getProperty('dockerVersion');
    $dockerVersionProperty->setAccessible(true);
    $dockerVersionProperty->setValue($action, '24.0');

    $method = $reflection->getMethod('getDebianDockerInstallCommand');
    $method->setAccessible(true);
    $command = $method->invoke($action);

    expect($command)
        ->toContain('DOCKER_CODENAME=${VERSION_CODENAME}')
        ->toContain('/dists/${VERSION_CODENAME}/Release')
        ->toContain('DOCKER_CODENAME=bookworm')
        ->toContain('${DOCKER_CODENAME} stable');
});
