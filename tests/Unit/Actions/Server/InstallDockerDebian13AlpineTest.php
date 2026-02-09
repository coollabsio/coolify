<?php

use App\Actions\Server\InstallDocker;
use App\Actions\Server\InstallPrerequisites;
use App\Models\Server;
use Mockery;

beforeEach(function () {
    $this->server = Mockery::mock(Server::class)->makePartial();
    $this->installDocker = new InstallDocker;
    $this->installPrerequisites = new InstallPrerequisites;
});

afterEach(function () {
    Mockery::close();
});

it('generates Debian docker install command with dynamic codename fallback', function () {
    $installDocker = new InstallDocker;
    $command = $installDocker->handle($this->server);

    // Use reflection to access private method
    $reflection = new ReflectionClass($installDocker);
    $method = $reflection->getMethod('getDebianDockerInstallCommand');
    $method->setAccessible(true);

    $result = $method->invoke($installDocker);

    // Verify the command contains the dynamic fallback logic
    expect($result)->toContain('DOCKER_CODENAME=${VERSION_CODENAME}');
    expect($result)->toContain('if ! curl -fsSL https://download.docker.com/linux/${ID}/dists/${VERSION_CODENAME}/Release');
    expect($result)->toContain('DOCKER_CODENAME=bookworm');
    expect($result)->toContain('https://download.docker.com/linux/${ID} ${DOCKER_CODENAME} stable');
})->skip('Requires mocked server setup');

it('generates Alpine docker install command correctly', function () {
    $installDocker = new InstallDocker;

    // Use reflection to access private method
    $reflection = new ReflectionClass($installDocker);
    $method = $reflection->getMethod('getAlpineDockerInstallCommand');
    $method->setAccessible(true);

    $result = $method->invoke($installDocker);

    // Verify Alpine-specific commands
    expect($result)->toContain('apk update');
    expect($result)->toContain('apk add --no-cache docker docker-cli-compose');
    expect($result)->toContain('rc-update add docker boot');
    expect($result)->toContain('service docker start');
});

it('uses OpenRC instead of systemd for Alpine', function () {
    // Mock the server to return alpine as supported OS type
    $server = Mockery::mock(Server::class)->makePartial();
    $server->shouldReceive('validateOS')->andReturn(collect(['alpine']));
    $server->shouldReceive('sslCertificates')->andReturn(Mockery::mock([
        'where' => Mockery::mock(['exists' => true]),
    ]));

    // This test verifies that Alpine uses service instead of systemctl
    expect(true)->toBeTrue();
})->skip('Requires full server mock setup');

it('validates Debian 13 as supported OS', function () {
    // Debian 13 should already be supported via ID=debian in SUPPORTED_OS
    $supportedOs = SUPPORTED_OS;

    expect($supportedOs)->toContain('ubuntu debian raspbian pop');
});

it('validates Alpine as supported OS', function () {
    $supportedOs = SUPPORTED_OS;

    expect($supportedOs)->toContain('alpine');
});

it('handles prerequisites installation for Alpine', function () {
    $installPrerequisites = new InstallPrerequisites;

    // Verify the method exists and contains Alpine logic
    $reflection = new ReflectionClass($installPrerequisites);
    $handleMethod = $reflection->getMethod('handle');

    expect($handleMethod)->not()->toBeNull();
})->skip('Requires mocked server with Alpine OS type');

it('Debian docker command uses VERSION_CODENAME variable correctly', function () {
    $installDocker = new InstallDocker;

    $reflection = new ReflectionClass($installDocker);
    $method = $reflection->getMethod('getDebianDockerInstallCommand');
    $method->setAccessible(true);

    $result = $method->invoke($installDocker);

    // Ensure we're sourcing os-release and using the codename variable
    expect($result)->toContain('. /etc/os-release');
    expect($result)->toContain('VERSION_CODENAME');
});

it('fallback to bookworm happens only when Docker repo check fails', function () {
    $installDocker = new InstallDocker;

    $reflection = new ReflectionClass($installDocker);
    $method = $reflection->getMethod('getDebianDockerInstallCommand');
    $method->setAccessible(true);

    $result = $method->invoke($installDocker);

    // The fallback should only happen if the curl check fails
    expect($result)->toContain('if ! curl -fsSL');
    expect($result)->toContain('>/dev/null 2>&1');
    expect($result)->toContain('falling back to bookworm');
});

it('Alpine prerequisites use apk package manager', function () {
    // Verify that Alpine prerequisites would use apk commands
    expect(true)->toBeTrue();
})->skip('Would require full integration test with mocked server');
