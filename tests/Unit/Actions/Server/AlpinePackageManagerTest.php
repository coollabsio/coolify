<?php

use App\Actions\Server\CheckUpdates;
use App\Actions\Server\InstallDocker;
use App\Actions\Server\InstallPrerequisites;

it('installs Bash while bootstrapping Alpine prerequisites', function () {
    $method = new ReflectionMethod(InstallPrerequisites::class, 'getAlpinePrerequisiteCommands');

    $commands = $method->invoke(new InstallPrerequisites);

    expect($commands)->toContain('command -v bash >/dev/null || apk add bash');
});

it('installs every Docker CLI plugin required on Alpine', function () {
    $method = new ReflectionMethod(InstallDocker::class, 'getAlpineDockerInstallCommand');

    $command = $method->invoke(new InstallDocker);

    expect($command)->toContain('apk add docker docker-cli-buildx docker-cli-compose');
});

it('uses OpenRC instead of systemd to restart Docker on Alpine', function () {
    $method = new ReflectionMethod(InstallDocker::class, 'getDockerServiceCommands');

    $action = new InstallDocker;
    $commands = $method->invoke($action, true);

    expect($commands)
        ->toBe(['rc-update add docker default', 'rc-service docker restart'])
        ->each->not->toContain('systemctl')
        ->and($method->invoke($action, false))
        ->toBe(['systemctl enable docker >/dev/null 2>&1 || true', 'systemctl restart docker']);
});

it('parses Alpine package updates', function () {
    $method = new ReflectionMethod(CheckUpdates::class, 'parseApkOutput');
    $output = <<<'OUTPUT'
docker-cli-compose-2.31.0-r5 x86_64 {docker-cli-compose} (Apache-2.0) [upgradable from: docker-cli-compose-2.31.0-r4]
libcrypto3-3.3.4-r0 aarch64 {openssl} (Apache-2.0) [upgradable from: libcrypto3-3.3.3-r0]
OUTPUT;

    $result = $method->invoke(new CheckUpdates, $output);

    expect($result)->toBe([
        'total_updates' => 2,
        'updates' => [
            [
                'package' => 'docker-cli-compose',
                'new_version' => '2.31.0-r5',
                'architecture' => 'x86_64',
                'current_version' => '2.31.0-r4',
            ],
            [
                'package' => 'libcrypto3',
                'new_version' => '3.3.4-r0',
                'architecture' => 'aarch64',
                'current_version' => '3.3.3-r0',
            ],
        ],
    ]);
});
