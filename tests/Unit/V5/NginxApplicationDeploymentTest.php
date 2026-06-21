<?php

use App\Actions\V5\Application\DeployNginxApplication;
use App\Models\V5\Application;
use App\Services\Flux\FluxClient;
use Tests\TestCase;

uses(TestCase::class);

it('builds an nginx container spec for the coold mesh runtime', function () {
    $application = new Application([
        'name' => 'nginx-test',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'coolify-v5-nginx-test',
        'mesh_namespace' => 'default',
        'status' => 'creating',
    ]);

    $action = new DeployNginxApplication(Mockery::mock(FluxClient::class));
    $method = new ReflectionMethod($action, 'containerSpec');
    $method->setAccessible(true);
    $spec = $method->invoke($action, $application);

    expect($spec)
        ->toMatchArray([
            'name' => 'coolify-v5-nginx-test',
            'image' => 'docker.io/library/nginx:alpine',
            'networks' => ['coolify-default-mesh'],
            'network_aliases' => ['coolify-v5-nginx-test'],
            'dns_search' => ['default.coolify.internal'],
            'restart_policy' => 'unless-stopped',
        ])
        ->not->toHaveKey('command')
        ->not->toHaveKey('privileged');
});
