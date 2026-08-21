<?php

use App\Actions\Migration\DetectIndependentCoolifyInstall;

test('managed host with only proxy and sentinel is not an independent coolify install', function () {
    $output = implode("\n", ['coolify-proxy', 'coolify-sentinel', 'my-app-uuid']);

    expect(DetectIndependentCoolifyInstall::isIndependentInstall($output))->toBeFalse();
});

test('dashboard container named coolify is an independent install', function () {
    $output = implode("\n", ['coolify', 'coolify-proxy', 'coolify-db']);

    expect(DetectIndependentCoolifyInstall::isIndependentInstall($output))->toBeTrue();
});

test('two control-plane containers without the dashboard name still count as an install', function () {
    $output = implode("\n", ['coolify-db', 'coolify-redis', 'coolify-proxy']);

    expect(DetectIndependentCoolifyInstall::isIndependentInstall($output))->toBeTrue();
});

test('source env file is enough to detect a coolify install even with no containers', function () {
    expect(DetectIndependentCoolifyInstall::isIndependentInstall('', true))->toBeTrue();
});

test('empty docker output is not an independent install', function () {
    expect(DetectIndependentCoolifyInstall::isIndependentInstall(''))->toBeFalse();
});
