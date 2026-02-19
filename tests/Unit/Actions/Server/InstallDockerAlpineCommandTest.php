<?php

use App\Actions\Server\InstallDocker;

it('has alpine docker install command with openrc-friendly startup', function () {
    $action = new InstallDocker;

    $reflection = new ReflectionClass($action);
    $method = $reflection->getMethod('getAlpineDockerInstallCommand');
    $method->setAccessible(true);

    $command = $method->invoke($action);

    expect($command)->toBeString()
        ->and($command)->toContain('apk add --no-cache docker docker-cli-compose')
        ->and($command)->toContain('rc-update add docker default')
        ->and($command)->toContain('rc-service docker start');
});
