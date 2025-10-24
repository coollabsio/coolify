<?php

use App\Jobs\ScheduledJobManager;
use Illuminate\Queue\Middleware\WithoutOverlapping;

it('uses WithoutOverlapping middleware with expireAfter to prevent stale locks', function () {
    $job = new ScheduledJobManager;
    $middleware = $job->middleware();

    // Assert middleware exists
    expect($middleware)->toBeArray()
        ->and($middleware)->toHaveCount(1);

    $overlappingMiddleware = $middleware[0];

    // Assert it's a WithoutOverlapping instance
    expect($overlappingMiddleware)->toBeInstanceOf(WithoutOverlapping::class);

    // Use reflection to check private properties
    $reflection = new ReflectionClass($overlappingMiddleware);

    // Check expireAfter is set (should be 60 seconds - matches job frequency)
    $expiresAfterProperty = $reflection->getProperty('expiresAfter');
    $expiresAfterProperty->setAccessible(true);
    $expiresAfter = $expiresAfterProperty->getValue($overlappingMiddleware);

    expect($expiresAfter)->toBe(60)
        ->and($expiresAfter)->toBeGreaterThan(0, 'expireAfter must be set to prevent stale locks');

    // Check releaseAfter is NOT set (we use dontRelease)
    $releaseAfterProperty = $reflection->getProperty('releaseAfter');
    $releaseAfterProperty->setAccessible(true);
    $releaseAfter = $releaseAfterProperty->getValue($overlappingMiddleware);

    expect($releaseAfter)->toBeNull('releaseAfter should be null when using dontRelease()');

    // Check the lock key
    $keyProperty = $reflection->getProperty('key');
    $keyProperty->setAccessible(true);
    $key = $keyProperty->getValue($overlappingMiddleware);

    expect($key)->toBe('scheduled-job-manager');
});

it('prevents stale locks by ensuring expireAfter is always set', function () {
    $job = new ScheduledJobManager;
    $middleware = $job->middleware();

    $overlappingMiddleware = $middleware[0];
    $reflection = new ReflectionClass($overlappingMiddleware);

    $expiresAfterProperty = $reflection->getProperty('expiresAfter');
    $expiresAfterProperty->setAccessible(true);
    $expiresAfter = $expiresAfterProperty->getValue($overlappingMiddleware);

    // Critical check: expireAfter MUST be set to prevent GitHub issue #4539
    expect($expiresAfter)->not->toBeNull(
        'expireAfter() is required to prevent stale locks (see GitHub #4539)'
    );
});
