<?php

use App\Livewire\Project\Shared\EnvironmentVariable\All;

it('canLoadEnvExample returns false for non-Application resources', function () {
    $component = new All;
    $component->resourceClass = 'App\Models\Service';

    expect($component->getCanLoadEnvExampleProperty())->toBeFalse();
});

it('canLoadEnvExample returns false for StandalonePostgresql resources', function () {
    $component = new All;
    $component->resourceClass = 'App\Models\StandalonePostgresql';

    expect($component->getCanLoadEnvExampleProperty())->toBeFalse();
});

it('canLoadEnvExample returns false for dockerfile applications', function () {
    $mockApplication = Mockery::mock('App\Models\Application')->shouldIgnoreMissing();
    $mockApplication->shouldReceive('getAttribute')
        ->with('dockerfile')
        ->andReturn('FROM alpine');

    $component = new All;
    $component->resourceClass = 'App\Models\Application';
    $component->resource = $mockApplication;

    expect($component->getCanLoadEnvExampleProperty())->toBeFalse();
});

it('canLoadEnvExample returns true for source deployment type', function () {
    $mockApplication = Mockery::mock('App\Models\Application')->shouldIgnoreMissing();
    $mockApplication->shouldReceive('getAttribute')
        ->with('dockerfile')
        ->andReturn(null);
    $mockApplication->shouldReceive('deploymentType')
        ->andReturn('source');

    $component = new All;
    $component->resourceClass = 'App\Models\Application';
    $component->resource = $mockApplication;

    expect($component->getCanLoadEnvExampleProperty())->toBeTrue();
});

it('canLoadEnvExample returns true for deploy_key deployment type', function () {
    $mockApplication = Mockery::mock('App\Models\Application')->shouldIgnoreMissing();
    $mockApplication->shouldReceive('getAttribute')
        ->with('dockerfile')
        ->andReturn(null);
    $mockApplication->shouldReceive('deploymentType')
        ->andReturn('deploy_key');

    $component = new All;
    $component->resourceClass = 'App\Models\Application';
    $component->resource = $mockApplication;

    expect($component->getCanLoadEnvExampleProperty())->toBeTrue();
});

it('canLoadEnvExample returns false for other deployment types', function () {
    $mockApplication = Mockery::mock('App\Models\Application')->shouldIgnoreMissing();
    $mockApplication->shouldReceive('getAttribute')
        ->with('dockerfile')
        ->andReturn(null);
    $mockApplication->shouldReceive('deploymentType')
        ->andReturn('dockerimage');

    $component = new All;
    $component->resourceClass = 'App\Models\Application';
    $component->resource = $mockApplication;

    expect($component->getCanLoadEnvExampleProperty())->toBeFalse();
});

it('loadFromEnvExample method exists on All component', function () {
    $component = new All;

    expect(method_exists($component, 'loadFromEnvExample'))->toBeTrue();
});

it('parseEnvFormatToArray correctly parses env file format', function () {
    $content = <<<'ENV'
# Comment line
DATABASE_URL=postgres://localhost:5432
APP_NAME="My App"
EMPTY_VALUE=
QUOTED='single quoted'
ENV;

    $result = parseEnvFormatToArray($content);

    expect($result)->toBeArray()
        ->and($result['DATABASE_URL'])->toBe('postgres://localhost:5432')
        ->and($result['APP_NAME'])->toBe('My App')
        ->and($result['EMPTY_VALUE'])->toBe('')
        ->and($result['QUOTED'])->toBe('single quoted')
        ->and($result)->not->toHaveKey('# Comment line');
});
