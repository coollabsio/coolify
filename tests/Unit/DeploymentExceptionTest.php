<?php

use App\Exceptions\DeploymentException;
use Illuminate\Contracts\Debug\ExceptionHandler;

test('DeploymentException is in the dontReport list', function () {
    $handler = app(ExceptionHandler::class);

    $exception = new DeploymentException('Test deployment failure');

    $reflection = new ReflectionClass($handler);
    $method = $reflection->getMethod('shouldReport');
    $method->setAccessible(true);

    expect($method->invoke($handler, $exception))->toBeFalse();
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
    $handler = app(ExceptionHandler::class);

    $exception = new DeploymentException('Test deployment failure');

    $reflection = new ReflectionClass($handler);
    $method = $reflection->getMethod('shouldReport');
    $method->setAccessible(true);

    expect($method->invoke($handler, $exception))->toBeFalse();
});

test('RuntimeException is still reported when thrown', function () {
    $handler = app(ExceptionHandler::class);

    $exception = new RuntimeException('Unexpected error in Coolify code');

    $reflection = new ReflectionClass($handler);
    $method = $reflection->getMethod('shouldReport');
    $method->setAccessible(true);

    expect($method->invoke($handler, $exception))->toBeTrue();
});
