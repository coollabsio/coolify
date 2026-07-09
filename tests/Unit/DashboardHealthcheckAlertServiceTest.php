<?php

use App\Services\DashboardHealthcheckAlertService;

function invokeContainerHasHealthcheck(?string $compose, string $containerName): bool
{
    $service = app(DashboardHealthcheckAlertService::class);
    $method = new ReflectionMethod($service, 'containerHasHealthcheck');
    $composeMethod = new ReflectionMethod($service, 'parseDockerCompose');
    $parsed = $composeMethod->invoke($service, $compose);

    return $method->invoke($service, $parsed, $containerName);
}

it('detects compose containers without a healthcheck block', function () {
    $compose = <<<'YAML'
services:
  web:
    image: nginx:latest
YAML;

    expect(invokeContainerHasHealthcheck($compose, 'web'))->toBeFalse();
});

it('detects compose containers with a healthcheck block', function () {
    $compose = <<<'YAML'
services:
  web:
    image: nginx:latest
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost"]
YAML;

    expect(invokeContainerHasHealthcheck($compose, 'web'))->toBeTrue();
});

it('treats exclude_from_hc compose services as without healthcheck', function () {
    $compose = <<<'YAML'
services:
  worker:
    image: worker:latest
    exclude_from_hc: true
    healthcheck:
      test: ["CMD", "true"]
YAML;

    expect(invokeContainerHasHealthcheck($compose, 'worker'))->toBeFalse();
});

it('treats restart no compose services as without healthcheck', function () {
    $compose = <<<'YAML'
services:
  job:
    image: job:latest
    restart: no
    healthcheck:
      test: ["CMD", "true"]
YAML;

    expect(invokeContainerHasHealthcheck($compose, 'job'))->toBeFalse();
});

it('returns false for unknown compose service names', function () {
    $compose = <<<'YAML'
services:
  web:
    image: nginx:latest
YAML;

    expect(invokeContainerHasHealthcheck($compose, 'missing'))->toBeFalse();
});
