<?php

use App\Models\Application;

it('does not treat a bare running status as a failed healthcheck', function () {
    expect(Application::aggregateMultiServerStatus('running:healthy', ['running']))
        ->toBe('running:healthy');
});

it('keeps an explicit health mismatch', function () {
    expect(Application::aggregateMultiServerStatus('running:healthy', ['running:unhealthy']))
        ->toBe('running:unhealthy');
});

it('marks the container state degraded when an additional server is not running', function () {
    expect(Application::aggregateMultiServerStatus('running:healthy', ['exited']))
        ->toBe('degraded:healthy');
});

it('does not read the container state as the health when the separator is missing', function () {
    expect(Application::splitCompositeStatus('running'))->toBe(['running', null]);
    expect(Application::splitCompositeStatus('running:healthy'))->toBe(['running', 'healthy']);
    expect(Application::splitCompositeStatus('running (healthy)'))->toBe(['running', 'healthy']);
});
