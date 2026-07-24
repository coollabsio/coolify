<?php

use App\Actions\Docker\GetContainersStatus;

function invokeGetContainersStatusMethod(string $method, array $args)
{
    $action = new GetContainersStatus;
    $reflection = new ReflectionMethod(GetContainersStatus::class, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke($action, ...$args);
}

it('ignores excluded containers when computing the max restart count', function () {
    $maxRestartCount = invokeGetContainersStatusMethod('maxRestartCountExcluding', [
        collect(['web' => 0, 'cron' => 15, 'sidecar' => 20]),
        collect(['cron', 'sidecar']),
    ]);

    expect($maxRestartCount)->toBe(0);
});

it('still counts restarts for non-excluded containers', function () {
    $maxRestartCount = invokeGetContainersStatusMethod('maxRestartCountExcluding', [
        collect(['web' => 20, 'cron' => 3]),
        collect(['cron']),
    ]);

    expect($maxRestartCount)->toBe(20);
});

it('preserves legacy behaviour when nothing is excluded', function () {
    $maxRestartCount = invokeGetContainersStatusMethod('maxRestartCountExcluding', [
        collect(['web' => 5, 'worker' => 12]),
        collect(),
    ]);

    expect($maxRestartCount)->toBe(12);
});

it('returns zero for an empty restart count collection', function () {
    $maxRestartCount = invokeGetContainersStatusMethod('maxRestartCountExcluding', [
        collect(),
        collect(),
    ]);

    expect($maxRestartCount)->toBe(0);
});

it('treats restart:no and exclude_from_hc services as excluded from the restart limit', function () {
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

    $excludedContainers = invokeGetContainersStatusMethod('getExcludedContainersFromDockerCompose', [$dockerComposeRaw]);

    expect($excludedContainers->all())->toContain('cron', 'ofelia')
        ->and($excludedContainers->all())->not->toContain('web');

    $maxRestartCount = invokeGetContainersStatusMethod('maxRestartCountExcluding', [
        collect(['web' => 1, 'cron' => 30, 'ofelia' => 50]),
        $excludedContainers,
    ]);

    expect($maxRestartCount)->toBe(1);
});
