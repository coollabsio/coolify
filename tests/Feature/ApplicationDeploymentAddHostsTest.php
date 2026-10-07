<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Models\ApplicationDeploymentQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function addHostFlagsFor(array $containers): string
{
    $job = (new ReflectionClass(ApplicationDeploymentJob::class))->newInstanceWithoutConstructor();

    return (new ReflectionMethod(ApplicationDeploymentJob::class, 'addHostFlags'))->invoke($job, collect($containers));
}

it('keeps build helper containers of other deployments out of --add-host', function () {
    ApplicationDeploymentQueue::create(['application_id' => '1', 'deployment_uuid' => 'k0s8g4wc4c8gkkso0gk4cgww']);

    $flags = addHostFlagsFor([
        'a' => ['Name' => 'redis-db', 'IPv4Address' => '10.0.1.5/24'],
        'b' => ['Name' => 'k0s8g4wc4c8gkkso0gk4cgww', 'IPv4Address' => '10.0.1.9/24'],
        'c' => ['Name' => 'coolify-proxy', 'IPv4Address' => '10.0.1.2/24'],
        'd' => ['Name' => 'app-uuid-20260908T141530', 'IPv4Address' => '10.0.1.7/24'],
    ]);

    expect($flags)->toBe('--add-host redis-db:10.0.1.5');
});

it('keeps the --add-host set stable whether or not a build helper is running', function () {
    ApplicationDeploymentQueue::create(['application_id' => '1', 'deployment_uuid' => 'helper-deployment-uuid']);
    $database = ['Name' => 'postgres-main', 'IPv4Address' => '10.0.1.3/24'];

    expect(addHostFlagsFor([$database, ['Name' => 'helper-deployment-uuid', 'IPv4Address' => '10.0.1.4/24']]))
        ->toBe(addHostFlagsFor([$database]))
        ->toBe('--add-host postgres-main:10.0.1.3');
});
