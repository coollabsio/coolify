<?php

use App\Actions\Server\InstallDocker;
use App\Models\Server;

it('detects supported OS using ID', function () {
    $parsed = Server::parseOsRelease("ID=debian\nVERSION_ID=13\nVERSION_CODENAME=trixie\n");
    $detected = Server::detectSupportedOsType($parsed);

    expect((string) $detected)->toContain('debian');
});

it('detects supported OS using ID_LIKE fallback', function () {
    $parsed = Server::parseOsRelease("ID=customlinux\nID_LIKE=debian\nVERSION_ID=1\n");
    $detected = Server::detectSupportedOsType($parsed);

    expect((string) $detected)->toContain('debian');
});

it('returns false for unsupported OS', function () {
    $parsed = Server::parseOsRelease("ID=myos\nID_LIKE=unknown\n");

    expect(Server::detectSupportedOsType($parsed))->toBeFalse();
});

it('uses codename fallback logic in debian docker install command', function () {
    $action = new InstallDocker;
    $reflection = new ReflectionClass($action);
    $method = $reflection->getMethod('getDebianDockerInstallCommand');
    $method->setAccessible(true);
    $command = $method->invoke($action);

    expect($command)
        ->toContain('CODENAME="${VERSION_CODENAME:-}"')
        ->toContain('if [ "$ID" = "debian" ] && [ "$CODENAME" = "trixie" ]; then CODENAME=bookworm; fi')
        ->toContain('${CODENAME}');
});

it('contains alpine docker install command', function () {
    $action = new InstallDocker;
    $reflection = new ReflectionClass($action);
    $method = $reflection->getMethod('getAlpineDockerInstallCommand');
    $method->setAccessible(true);
    $command = $method->invoke($action);

    expect($command)
        ->toContain('apk add --no-cache docker docker-cli docker-cli-compose')
        ->toContain('service docker start');
});
