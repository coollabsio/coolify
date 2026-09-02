<?php

use App\Jobs\PushServerUpdateJob;

it('does not persist a proxy status when sentinel did not find the proxy', function () {
    expect(PushServerUpdateJob::proxyStatusToPersist(false, 'running'))->toBeNull()
        ->and(PushServerUpdateJob::proxyStatusToPersist(false, 'exited'))->toBeNull();
});

it('persists the observed docker state when sentinel found the proxy', function () {
    expect(PushServerUpdateJob::proxyStatusToPersist(true, 'running'))->toBe('running')
        ->and(PushServerUpdateJob::proxyStatusToPersist(true, 'restarting'))->toBe('restarting');
});

it('strips a health suffix so the dashboard can compare against running', function () {
    expect(PushServerUpdateJob::proxyStatusToPersist(true, 'running:healthy'))->toBe('running')
        ->and(PushServerUpdateJob::proxyStatusToPersist(true, 'running:unhealthy'))->toBe('running');
});

it('defaults to running when the proxy was found without an observed state', function () {
    expect(PushServerUpdateJob::proxyStatusToPersist(true, null))->toBe('running')
        ->and(PushServerUpdateJob::proxyStatusToPersist(true, ''))->toBe('running');
});
