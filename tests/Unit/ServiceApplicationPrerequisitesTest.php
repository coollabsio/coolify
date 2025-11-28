<?php

use App\Models\Service;
use App\Models\ServiceApplication;
use Illuminate\Database\Eloquent\Collection;

it('applies beszel gzip prerequisite correctly', function () {
    $application = Mockery::mock(ServiceApplication::class);
    $application->shouldReceive('save')->once();
    $application->is_gzip_enabled = true; // Start as enabled

    $query = Mockery::mock();
    $query->shouldReceive('whereName')
        ->with('beszel')
        ->once()
        ->andReturnSelf();
    $query->shouldReceive('first')
        ->once()
        ->andReturn($application);

    $service = Mockery::mock(Service::class);
    $service->name = 'beszel-test-uuid';
    $service->id = 1;
    $service->shouldReceive('applications')
        ->once()
        ->andReturn($query);

    applyServiceApplicationPrerequisites($service);

    expect($application->is_gzip_enabled)->toBeFalse();
});

it('applies appwrite stripprefix prerequisite correctly', function () {
    $applications = [];

    foreach (['appwrite', 'appwrite-console', 'appwrite-realtime'] as $name) {
        $app = Mockery::mock(ServiceApplication::class);
        $app->is_stripprefix_enabled = true; // Start as enabled
        $app->shouldReceive('save')->once();
        $applications[$name] = $app;
    }

    $service = Mockery::mock(Service::class);
    $service->name = 'appwrite-test-uuid';
    $service->id = 1;

    $service->shouldReceive('applications')->times(3)->andReturnUsing(function () use (&$applications) {
        static $callCount = 0;
        $names = ['appwrite', 'appwrite-console', 'appwrite-realtime'];
        $currentName = $names[$callCount++];

        $query = Mockery::mock();
        $query->shouldReceive('whereName')
            ->with($currentName)
            ->once()
            ->andReturnSelf();
        $query->shouldReceive('first')
            ->once()
            ->andReturn($applications[$currentName]);

        return $query;
    });

    applyServiceApplicationPrerequisites($service);

    foreach ($applications as $app) {
        expect($app->is_stripprefix_enabled)->toBeFalse();
    }
});

it('handles missing applications gracefully', function () {
    $query = Mockery::mock();
    $query->shouldReceive('whereName')
        ->with('beszel')
        ->once()
        ->andReturnSelf();
    $query->shouldReceive('first')
        ->once()
        ->andReturn(null);

    $service = Mockery::mock(Service::class);
    $service->name = 'beszel-test-uuid';
    $service->id = 1;
    $service->shouldReceive('applications')
        ->once()
        ->andReturn($query);

    // Should not throw exception
    applyServiceApplicationPrerequisites($service);

    expect(true)->toBeTrue();
});

it('skips services without prerequisites', function () {
    $service = Mockery::mock(Service::class);
    $service->name = 'unknown-service-uuid';
    $service->id = 1;
    $service->shouldNotReceive('applications');

    applyServiceApplicationPrerequisites($service);

    expect(true)->toBeTrue();
});
