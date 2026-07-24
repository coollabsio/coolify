<?php

use App\Actions\Docker\GetContainersStatus;

/**
 * Regression tests for issue #10624.
 *
 * Containers that are excluded from health checks (exclude_from_hc: true or restart: no)
 * must also be excluded from the crash restart limit, so legitimately scheduled / one-shot
 * sidecar containers (e.g. Ofelia jobs) do not inflate the restart count and stop the app.
 */
function invokeGetContainersStatusMethod(string $method, array $args)
{
    $action = new GetContainersStatus;
    $reflection = new ReflectionMethod(GetContainersStatus::class, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke($action, ...$args);
}

it('ignores excluded containers when computing the max restart count', function () {
    $restartCounts = collect([
        'web' => 0,
        'cron' => 15,
        'sidecar' => 20,
    ]);
    $excludedContainers = collect(['cron', 'sidecar']);

    $maxRestartCount = invokeGetContainersStatusMethod(
        'maxRestartCountExcludingExcluded',
        [$restartCounts, $excludedContainers]
    );

    // Only "web" (0 restarts) is relevant, so the crash restart limit is never reached.
    expect($maxRestartCount)->toBe(0);
});

it('still counts restarts for non-excluded containers', function () {
    $restartCounts = collect([
        'web' => 20,
        'cron' => 3,
    ]);
    $excludedContainers = collect(['cron']);

    $maxRestartCount = invokeGetContainersStatusMethod(
        'maxRestartCountExcludingExcluded',
        [$restartCounts, $excludedContainers]
    );

    // A genuine crash loop on "web" is unaffected by the exclusion of "cron".
    expect($maxRestartCount)->toBe(20);
});

it('preserves legacy behaviour when nothing is excluded', function () {
    $restartCounts = collect([
        'web' => 5,
        'worker' => 12,
    ]);

    $maxRestartCount = invokeGetContainersStatusMethod(
        'maxRestartCountExcludingExcluded',
        [$restartCounts, collect()]
    );

    expect($maxRestartCount)->toBe(12);
});

it('returns zero for an empty restart count collection', function () {
    $maxRestartCount = invokeGetContainersStatusMethod(
        'maxRestartCountExcludingExcluded',
        [collect(), collect()]
    );

    expect($maxRestartCount)->toBe(0);
});

it('treats restart:no and exclude_from_hc services as excluded from the restart limit', function () {
    // Compose with a normally-monitored service, a one-shot job (restart: no),
    // and an explicitly excluded scheduler sidecar (exclude_from_hc: true).
    $dockerComposeRaw = <<<'YAML'
    services:
      web:
        image: nginx
        restart: unless-stopped
      cron:
        image: busybox
        restart: no
      ofelia:
        image: mcuadros/ofelia:latest
        restart: unless-stopped
        exclude_from_hc: true
    YAML;

    $excludedContainers = invokeGetContainersStatusMethod(
        'getExcludedContainersFromDockerCompose',
        [$dockerComposeRaw]
    );

    expect($excludedContainers->all())->toContain('cron', 'ofelia')
        ->and($excludedContainers->all())->not->toContain('web');

    // The excluded services' restart counts are dropped, leaving only "web".
    $restartCounts = collect([
        'web' => 1,
        'cron' => 30,
        'ofelia' => 50,
    ]);

    $maxRestartCount = invokeGetContainersStatusMethod(
        'maxRestartCountExcludingExcluded',
        [$restartCounts, $excludedContainers]
    );

    expect($maxRestartCount)->toBe(1);
});
