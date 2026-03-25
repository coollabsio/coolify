<?php

use App\Livewire\Project\Service\Configuration;
use App\Models\Service;

describe('Laravel menu visibility', function () {
    it('shows laravel menus only when template keys exist in docker_compose_raw', function () {
        $service = Mockery::mock(Service::class)->makePartial();
        $service->docker_compose_raw = "services:\n  laravel:\n    image: php:${SERVICE_PHP_VERSION}-fpm-alpine\n";

        $envRel = Mockery::mock();
        $envRel->shouldReceive('whereIn')->never();

        $service->shouldReceive('environment_variables')->andReturn($envRel)->never();

        $component = new Configuration;
        $component->service = $service;

        expect($component->hasLaravel())->toBeTrue();
    });

    it('falls back to service environment variables when docker_compose_raw does not contain keys', function () {
        $service = Mockery::mock(Service::class)->makePartial();
        $service->docker_compose_raw = "services:\n  app:\n    image: php:fpm\n";

        $envQuery = Mockery::mock();
        $keys = ['SERVICE_GITHUB_REPO_URL', 'SERVICE_PHP_VERSION'];
        $envQuery->shouldReceive('exists')->once()->andReturnTrue();

        $envRel = Mockery::mock();
        $envRel->shouldReceive('whereIn')->with('key', $keys)->andReturn($envQuery);

        $service->shouldReceive('environment_variables')->andReturn($envRel);

        $component = new Configuration;
        $component->service = $service;

        expect($component->hasLaravel())->toBeTrue();
    });

    it('does not show menus when neither docker_compose_raw nor env vars contain keys', function () {
        $service = Mockery::mock(Service::class)->makePartial();
        $service->docker_compose_raw = "services:\n  app:\n    image: php:fpm\n";

        $envQuery = Mockery::mock();
        $keys = ['SERVICE_GITHUB_REPO_URL', 'SERVICE_PHP_VERSION'];
        $envQuery->shouldReceive('exists')->once()->andReturnFalse();

        $envRel = Mockery::mock();
        $envRel->shouldReceive('whereIn')->with('key', $keys)->andReturn($envQuery);

        $service->shouldReceive('environment_variables')->andReturn($envRel);

        $component = new Configuration;
        $component->service = $service;

        expect($component->hasLaravel())->toBeFalse();
    });
});

