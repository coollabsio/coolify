<?php

use App\Models\Application;
use App\Models\GithubApp;

it('returns deploy_key when private_key_id is a real key', function () {
    $application = new Application;
    $application->private_key_id = 5;

    expect($application->deploymentType())->toBe('deploy_key');
});

it('returns deploy_key when private_key_id is a real key even with source', function () {
    $application = Mockery::mock(Application::class)->makePartial();
    $application->private_key_id = 5;
    $application->shouldReceive('getAttribute')->with('source')->andReturn(new GithubApp);
    $application->shouldReceive('getAttribute')->with('private_key_id')->andReturn(5);

    expect($application->deploymentType())->toBe('deploy_key');
});

it('returns source when private_key_id is null and source exists', function () {
    $application = Mockery::mock(Application::class)->makePartial();
    $application->private_key_id = null;
    $application->shouldReceive('getAttribute')->with('source')->andReturn(new GithubApp);
    $application->shouldReceive('getAttribute')->with('private_key_id')->andReturn(null);

    expect($application->deploymentType())->toBe('source');
});

it('returns source when private_key_id is zero and source exists', function () {
    $application = Mockery::mock(Application::class)->makePartial();
    $application->private_key_id = 0;
    $application->shouldReceive('getAttribute')->with('source')->andReturn(new GithubApp);
    $application->shouldReceive('getAttribute')->with('private_key_id')->andReturn(0);

    expect($application->deploymentType())->toBe('source');
});

it('returns deploy_key when private_key_id is zero and no source', function () {
    $application = new Application;
    $application->private_key_id = 0;
    $application->source = null;

    expect($application->deploymentType())->toBe('deploy_key');
});

it('returns other when private_key_id is null and no source', function () {
    $application = Mockery::mock(Application::class)->makePartial();
    $application->shouldReceive('getAttribute')->with('source')->andReturn(null);
    $application->shouldReceive('getAttribute')->with('private_key_id')->andReturn(null);

    expect($application->deploymentType())->toBe('other');
});
