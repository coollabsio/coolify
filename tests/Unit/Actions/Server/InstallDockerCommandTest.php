<?php

use App\Actions\Server\InstallDocker;

it('maps Debian numeric codename 13 to trixie in APT fallback command', function () {
    $action = new InstallDocker;

    $dockerVersionProperty = new ReflectionProperty($action, 'dockerVersion');
    $dockerVersionProperty->setAccessible(true);
    $dockerVersionProperty->setValue($action, '27.3');

    $method = new ReflectionMethod($action, 'getDebianDockerInstallCommand');
    $method->setAccessible(true);
    $command = $method->invoke($action);

    expect($command)->toContain('DOCKER_VERSION_CODENAME=${VERSION_CODENAME:-${VERSION_ID}}')
        ->and($command)->toContain('if [ "${DOCKER_VERSION_CODENAME}" = "13" ]; then DOCKER_VERSION_CODENAME=trixie; fi')
        ->and($command)->toContain('https://download.docker.com/linux/${ID} ${DOCKER_VERSION_CODENAME} stable');
});
