<?php

use App\Actions\V5\Application\DeployNginxApplication;
use App\Models\V5\Application;
use Tests\TestCase;

uses(TestCase::class);

it('verifies nginx is running before marking the application running', function () {
    $application = new Application([
        'name' => 'nginx-test',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'coolify-v5-nginx-test',
        'status' => 'creating',
    ]);

    $action = new DeployNginxApplication;
    $method = new ReflectionMethod($action, 'remoteCommand');
    $method->setAccessible(true);
    $remoteCommand = $method->invoke($action, $application);

    expect($remoteCommand)
        ->toContain('if [ "$(id -u)" = "0" ]; then podman=podman; else podman="sudo -n podman"; fi')
        ->toContain("--network 'coolify-default-mesh'")
        ->toContain("--network-alias 'coolify-v5-nginx-test'")
        ->toContain('$podman inspect')
        ->not->toContain('docker run')
        ->not->toContain('docker inspect')
        ->toContain('.State.Running')
        ->toContain('Container did not stay running')
        ->toContain('exit 1');
});
