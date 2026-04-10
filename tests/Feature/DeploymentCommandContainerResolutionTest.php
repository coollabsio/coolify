<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;

function createJobWithProperties(string $uuid): object
{
    $ref = new ReflectionClass(ApplicationDeploymentJob::class);
    $instance = $ref->newInstanceWithoutConstructor();

    $app = Mockery::mock(Application::class)->makePartial();
    $app->uuid = $uuid;

    $queue = Mockery::mock(ApplicationDeploymentQueue::class)->makePartial();
    $queue->shouldReceive('addLogEntry')->andReturnNull();

    $appProp = $ref->getProperty('application');
    $appProp->setAccessible(true);
    $appProp->setValue($instance, $app);

    $queueProp = $ref->getProperty('application_deployment_queue');
    $queueProp->setAccessible(true);
    $queueProp->setValue($instance, $queue);

    return $instance;
}

function invokeResolve(object $instance, $containers, ?string $specifiedName, string $type): ?array
{
    $ref = new ReflectionClass(ApplicationDeploymentJob::class);
    $method = $ref->getMethod('resolveCommandContainer');
    $method->setAccessible(true);

    return $method->invoke($instance, $containers, $specifiedName, $type);
}

describe('resolveCommandContainer', function () {
    test('returns null when no containers exist', function () {
        $instance = createJobWithProperties('abc123');
        $result = invokeResolve($instance, collect([]), 'web', 'Pre-deployment');

        expect($result)->toBeNull();
    });

    test('returns the sole container when only one exists', function () {
        $container = ['Names' => 'web-abc123', 'Labels' => ''];
        $instance = createJobWithProperties('abc123');
        $result = invokeResolve($instance, collect([$container]), null, 'Pre-deployment');

        expect($result)->toBe($container);
    });

    test('returns the sole container regardless of specified name when only one exists', function () {
        $container = ['Names' => 'web-abc123', 'Labels' => ''];
        $instance = createJobWithProperties('abc123');
        $result = invokeResolve($instance, collect([$container]), 'wrong-name', 'Pre-deployment');

        expect($result)->toBe($container);
    });

    test('returns null when no container name specified for multi-container app', function () {
        $containers = collect([
            ['Names' => 'web-abc123', 'Labels' => ''],
            ['Names' => 'worker-abc123', 'Labels' => ''],
        ]);
        $instance = createJobWithProperties('abc123');
        $result = invokeResolve($instance, $containers, null, 'Pre-deployment');

        expect($result)->toBeNull();
    });

    test('returns null when empty string container name for multi-container app', function () {
        $containers = collect([
            ['Names' => 'web-abc123', 'Labels' => ''],
            ['Names' => 'worker-abc123', 'Labels' => ''],
        ]);
        $instance = createJobWithProperties('abc123');
        $result = invokeResolve($instance, $containers, '', 'Pre-deployment');

        expect($result)->toBeNull();
    });

    test('matches correct container by specified name in multi-container app', function () {
        $containers = collect([
            ['Names' => 'web-abc123', 'Labels' => ''],
            ['Names' => 'worker-abc123', 'Labels' => ''],
        ]);
        $instance = createJobWithProperties('abc123');
        $result = invokeResolve($instance, $containers, 'worker', 'Pre-deployment');

        expect($result)->toBe(['Names' => 'worker-abc123', 'Labels' => '']);
    });

    test('returns null when specified container name does not match any container', function () {
        $containers = collect([
            ['Names' => 'web-abc123', 'Labels' => ''],
            ['Names' => 'worker-abc123', 'Labels' => ''],
        ]);
        $instance = createJobWithProperties('abc123');
        $result = invokeResolve($instance, $containers, 'nonexistent', 'Pre-deployment');

        expect($result)->toBeNull();
    });

    test('matches container with PR suffix', function () {
        $containers = collect([
            ['Names' => 'web-abc123-pr-42', 'Labels' => ''],
            ['Names' => 'worker-abc123-pr-42', 'Labels' => ''],
        ]);
        $instance = createJobWithProperties('abc123');
        $result = invokeResolve($instance, $containers, 'web', 'Pre-deployment');

        expect($result)->toBe(['Names' => 'web-abc123-pr-42', 'Labels' => '']);
    });
});
