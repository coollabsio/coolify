<?php

use App\Actions\Service\RestartService;
use App\Actions\Service\StartService;
use App\Actions\Service\StopService;
use App\Models\Service;

it('does not stop a service before pulling latest images', function () {
    $method = new ReflectionMethod(StartService::class, 'shouldStopBeforeStarting');

    expect($method->invoke(new StartService, pullLatestImages: true, stopBeforeStart: true))->toBeFalse();
});

it('still stops a service before a regular restart', function () {
    $method = new ReflectionMethod(StartService::class, 'shouldStopBeforeStarting');

    expect($method->invoke(new StartService, pullLatestImages: false, stopBeforeStart: true))->toBeTrue()
        ->and($method->invoke(new StartService, pullLatestImages: false, stopBeforeStart: false))->toBeFalse();
});

it('routes service restart actions through start service with deferred stop semantics', function () {
    $service = Mockery::mock(Service::class);

    $stopService = Mockery::mock(StopService::class);
    $stopService->shouldNotReceive('handle');
    app()->instance(StopService::class, $stopService);

    $startService = Mockery::mock(StartService::class);
    $startService->shouldReceive('handle')
        ->once()
        ->with($service, true, true)
        ->andReturn('restart queued');
    app()->instance(StartService::class, $startService);

    expect(RestartService::run($service, true))->toBe('restart queued');
});
