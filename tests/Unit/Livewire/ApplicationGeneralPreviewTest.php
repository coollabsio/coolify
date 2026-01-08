<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Livewire\Project\Application\General;

it('prevents double slashes in build command preview when baseDirectory is root', function () {
    // Mock the component with properties
    $component = Mockery::mock(General::class)->makePartial();
    $component->baseDirectory = '/';
    $component->dockerComposeLocation = '/docker-compose.yaml';
    $component->dockerComposeCustomBuildCommand = 'docker compose build';

    $preview = $component->getDockerComposeBuildCommandPreviewProperty();

    // Should be ./docker-compose.yaml, NOT .//docker-compose.yaml
    expect($preview)
        ->toBeString()
        ->toContain('./docker-compose.yaml')
        ->not->toContain('.//');
});

it('correctly formats build command preview with nested baseDirectory', function () {
    $component = Mockery::mock(General::class)->makePartial();
    $component->baseDirectory = '/backend';
    $component->dockerComposeLocation = '/docker-compose.yaml';
    $component->dockerComposeCustomBuildCommand = 'docker compose build';

    $preview = $component->getDockerComposeBuildCommandPreviewProperty();

    // Should be ./backend/docker-compose.yaml
    expect($preview)
        ->toBeString()
        ->toContain('./backend/docker-compose.yaml');
});

it('correctly formats build command preview with deeply nested baseDirectory', function () {
    $component = Mockery::mock(General::class)->makePartial();
    $component->baseDirectory = '/apps/api/backend';
    $component->dockerComposeLocation = '/docker-compose.prod.yaml';
    $component->dockerComposeCustomBuildCommand = 'docker compose build';

    $preview = $component->getDockerComposeBuildCommandPreviewProperty();

    expect($preview)
        ->toBeString()
        ->toContain('./apps/api/backend/docker-compose.prod.yaml');
});

it('uses BUILD_TIME_ENV_PATH constant instead of hardcoded path in build command preview', function () {
    $component = Mockery::mock(General::class)->makePartial();
    $component->baseDirectory = '/';
    $component->dockerComposeLocation = '/docker-compose.yaml';
    $component->dockerComposeCustomBuildCommand = 'docker compose build';

    $preview = $component->getDockerComposeBuildCommandPreviewProperty();

    // Should contain the path from the constant
    expect($preview)
        ->toBeString()
        ->toContain(ApplicationDeploymentJob::BUILD_TIME_ENV_PATH);
});

it('returns empty string for build command preview when no custom build command is set', function () {
    $component = Mockery::mock(General::class)->makePartial();
    $component->baseDirectory = '/backend';
    $component->dockerComposeLocation = '/docker-compose.yaml';
    $component->dockerComposeCustomBuildCommand = null;

    $preview = $component->getDockerComposeBuildCommandPreviewProperty();

    expect($preview)->toBe('');
});

it('prevents double slashes in start command preview when baseDirectory is root', function () {
    $component = Mockery::mock(General::class)->makePartial();
    $component->baseDirectory = '/';
    $component->dockerComposeLocation = '/docker-compose.yaml';
    $component->dockerComposeCustomStartCommand = 'docker compose up -d';

    $preview = $component->getDockerComposeStartCommandPreviewProperty();

    // Should be ./docker-compose.yaml, NOT .//docker-compose.yaml
    expect($preview)
        ->toBeString()
        ->toContain('./docker-compose.yaml')
        ->not->toContain('.//');
});

it('correctly formats start command preview with nested baseDirectory', function () {
    $component = Mockery::mock(General::class)->makePartial();
    $component->baseDirectory = '/frontend';
    $component->dockerComposeLocation = '/compose.yaml';
    $component->dockerComposeCustomStartCommand = 'docker compose up -d';

    $preview = $component->getDockerComposeStartCommandPreviewProperty();

    expect($preview)
        ->toBeString()
        ->toContain('./frontend/compose.yaml');
});

it('uses workdir env placeholder in start command preview', function () {
    $component = Mockery::mock(General::class)->makePartial();
    $component->baseDirectory = '/';
    $component->dockerComposeLocation = '/docker-compose.yaml';
    $component->dockerComposeCustomStartCommand = 'docker compose up -d';

    $preview = $component->getDockerComposeStartCommandPreviewProperty();

    // Start command should use {workdir}/.env, not build-time env
    expect($preview)
        ->toBeString()
        ->toContain('{workdir}/.env')
        ->not->toContain(ApplicationDeploymentJob::BUILD_TIME_ENV_PATH);
});

it('returns empty string for start command preview when no custom start command is set', function () {
    $component = Mockery::mock(General::class)->makePartial();
    $component->baseDirectory = '/backend';
    $component->dockerComposeLocation = '/docker-compose.yaml';
    $component->dockerComposeCustomStartCommand = null;

    $preview = $component->getDockerComposeStartCommandPreviewProperty();

    expect($preview)->toBe('');
});

it('handles baseDirectory with trailing slash correctly in build command', function () {
    $component = Mockery::mock(General::class)->makePartial();
    $component->baseDirectory = '/backend/';
    $component->dockerComposeLocation = '/docker-compose.yaml';
    $component->dockerComposeCustomBuildCommand = 'docker compose build';

    $preview = $component->getDockerComposeBuildCommandPreviewProperty();

    // rtrim should remove trailing slash to prevent double slashes
    expect($preview)
        ->toBeString()
        ->toContain('./backend/docker-compose.yaml')
        ->not->toContain('backend//');
});

it('handles baseDirectory with trailing slash correctly in start command', function () {
    $component = Mockery::mock(General::class)->makePartial();
    $component->baseDirectory = '/backend/';
    $component->dockerComposeLocation = '/docker-compose.yaml';
    $component->dockerComposeCustomStartCommand = 'docker compose up -d';

    $preview = $component->getDockerComposeStartCommandPreviewProperty();

    // rtrim should remove trailing slash to prevent double slashes
    expect($preview)
        ->toBeString()
        ->toContain('./backend/docker-compose.yaml')
        ->not->toContain('backend//');
});
