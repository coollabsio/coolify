<?php

use App\Livewire\Project\Service\StackForm;
use App\Models\Service;

it('only enables laravel github fields when compose contains laravel keys', function () {
    $service = Mockery::mock(Service::class)->makePartial();
    $service->docker_compose_raw = "services:\n  app:\n    image: something\n";

    $component = new StackForm;
    $component->service = $service;
    $component->dockerComposeRaw = $service->docker_compose_raw;

    expect($component->isLaravelGitHubStack())->toBeFalse();

    $component->dockerComposeRaw = "services:\n  laravel:\n    environment:\n      - SERVICE_GITHUB_REPO_URL=\${SERVICE_GITHUB_REPO_URL}\n";
    expect($component->isLaravelGitHubStack())->toBeTrue();

    $component->dockerComposeRaw = "services:\n  laravel:\n    image: php:\${SERVICE_PHP_VERSION}-fpm-alpine\n";
    expect($component->isLaravelGitHubStack())->toBeTrue();
});

