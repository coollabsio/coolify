<?php

use App\Exceptions\DeploymentException;
use App\Exceptions\Handler;

test('DeploymentException is in the dontReport array', function () {
    $handler = new Handler(app());

    // Use reflection to access the protected $dontReport property
    $reflection = new ReflectionClass($handler);
    $property = $reflection->getProperty('dontReport');
    $property->setAccessible(true);
    $dontReport = $property->getValue($handler);

    expect($dontReport)->toContain(DeploymentException::class);
});

test('DeploymentException can be created with a message', function () {
    $exception = new DeploymentException('Test deployment error');

    expect($exception->getMessage())->toBe('Test deployment error');
    expect($exception)->toBeInstanceOf(Exception::class);
});

test('DeploymentException can be created with a message and code', function () {
    $exception = new DeploymentException('Test error', 69420);

    expect($exception->getMessage())->toBe('Test error');
    expect($exception->getCode())->toBe(69420);
});

test('DeploymentException can be created from another exception', function () {
    $originalException = new RuntimeException('Original error', 500);
    $deploymentException = DeploymentException::fromException($originalException);

    expect($deploymentException->getMessage())->toBe('Original error');
    expect($deploymentException->getCode())->toBe(500);
    expect($deploymentException->getPrevious())->toBe($originalException);
});

test('DeploymentException is not reported when thrown', function () {
    $handler = new Handler(app());

    // DeploymentException should not be reported (logged)
    $exception = new DeploymentException('Test deployment failure');

    // Check that the exception should not be reported
    $reflection = new ReflectionClass($handler);
    $method = $reflection->getMethod('shouldReport');
    $method->setAccessible(true);

    $shouldReport = $method->invoke($handler, $exception);

    expect($shouldReport)->toBeFalse();
});

test('RuntimeException is still reported when thrown', function () {
    $handler = new Handler(app());

    // RuntimeException should still be reported (this is for Coolify bugs)
    $exception = new RuntimeException('Unexpected error in Coolify code');

    // Check that the exception should be reported
    $reflection = new ReflectionClass($handler);
    $method = $reflection->getMethod('shouldReport');
    $method->setAccessible(true);

    $shouldReport = $method->invoke($handler, $exception);

    expect($shouldReport)->toBeTrue();
});
