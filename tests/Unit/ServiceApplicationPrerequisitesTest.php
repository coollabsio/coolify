<?php

use App\Models\Service;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    Log::shouldReceive('error')->andReturn(null);
});

it('applies beszel gzip prerequisite correctly', function () {
    // Create a simple object to track the property change
    $application = new class
    {
        public $is_gzip_enabled = true;

        public function save() {}
    };

    $query = Mockery::mock();
    $query->shouldReceive('whereName')
        ->with('beszel')
        ->once()
        ->andReturnSelf();
    $query->shouldReceive('first')
        ->once()
        ->andReturn($application);

    $service = Mockery::mock(Service::class)->makePartial();
    $service->name = 'beszel-clx1ab2cd3ef4g5hi6jk7l8m9n0o1p2q3'; // CUID2 format
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
        $app = new class
        {
            public $is_stripprefix_enabled = true;

            public function save() {}
        };
        $applications[$name] = $app;
    }

    $service = Mockery::mock(Service::class)->makePartial();
    $service->name = 'appwrite-clx1ab2cd3ef4g5hi6jk7l8m9n0o1p2q3'; // CUID2 format
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

    $service = Mockery::mock(Service::class)->makePartial();
    $service->name = 'beszel-clx1ab2cd3ef4g5hi6jk7l8m9n0o1p2q3'; // CUID2 format
    $service->id = 1;
    $service->shouldReceive('applications')
        ->once()
        ->andReturn($query);

    // Should not throw exception
    applyServiceApplicationPrerequisites($service);

    expect(true)->toBeTrue();
});

it('skips services without prerequisites', function () {
    $service = Mockery::mock(Service::class)->makePartial();
    $service->name = 'unknown-clx1ab2cd3ef4g5hi6jk7l8m9n0o1p2q3'; // CUID2 format
    $service->id = 1;
    $service->shouldNotReceive('applications');

    applyServiceApplicationPrerequisites($service);

    expect(true)->toBeTrue();
});

it('correctly parses service name with single hyphen', function () {
    $service = Mockery::mock(Service::class)->makePartial();
    $service->name = 'docker-registry-clx1ab2cd3ef4g5hi6jk7l8m9n0o1p2q3'; // CUID2 format
    $service->id = 1;
    $service->shouldNotReceive('applications');

    // Should not throw exception - validates that 'docker-registry' is correctly parsed
    applyServiceApplicationPrerequisites($service);

    expect(true)->toBeTrue();
});

it('correctly parses service name with multiple hyphens', function () {
    $service = Mockery::mock(Service::class)->makePartial();
    $service->name = 'elasticsearch-with-kibana-clx1ab2cd3ef4g5hi6jk7l8m9n0o1p2q3'; // CUID2 format
    $service->id = 1;
    $service->shouldNotReceive('applications');

    // Should not throw exception - validates that 'elasticsearch-with-kibana' is correctly parsed
    applyServiceApplicationPrerequisites($service);

    expect(true)->toBeTrue();
});

it('correctly parses service name with hyphens in template name', function () {
    $service = Mockery::mock(Service::class)->makePartial();
    $service->name = 'apprise-api-clx1ab2cd3ef4g5hi6jk7l8m9n0o1p2q3'; // CUID2 format
    $service->id = 1;
    $service->shouldNotReceive('applications');

    // Should not throw exception - validates that 'apprise-api' is correctly parsed
    applyServiceApplicationPrerequisites($service);

    expect(true)->toBeTrue();
});
