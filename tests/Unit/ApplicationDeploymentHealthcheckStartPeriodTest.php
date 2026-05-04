<?php

use App\Jobs\ApplicationDeploymentJob;

it('does not count docker start period status against healthcheck retries', function () {
    $job = Mockery::mock(ApplicationDeploymentJob::class)->makePartial();
    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);

    $method = $reflection->getMethod('shouldCountHealthcheckAttempt');
    $method->setAccessible(true);

    expect($method->invoke($job, 'starting'))->toBeFalse()
        ->and($method->invoke($job, 'healthy'))->toBeTrue()
        ->and($method->invoke($job, 'unhealthy'))->toBeTrue();
});
