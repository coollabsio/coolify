<?php

/**
 * @param  string|array<int, string>  $queue
 * @return array<int, string>
 */
function horizonSupervisorQueues(string|array $queue): array
{
    $queues = is_array($queue) ? $queue : explode(',', $queue);

    return array_map('trim', $queues);
}

it('defines a horizon supervisor that consumes the v5-reconcile queue', function () {
    $supervisors = config('horizon.defaults');

    $handlesReconcile = collect($supervisors)->contains(
        fn (array $config): bool => in_array('v5-reconcile', horizonSupervisorQueues($config['queue'] ?? ''), true)
    );

    expect($handlesReconcile)->toBeTrue();
});

it('provisions the v5-reconcile supervisor in both production and local environments', function () {
    expect(config('horizon.environments.production.v5reconcile'))->toBeArray()
        ->and(config('horizon.environments.local.v5reconcile'))->toBeArray()
        ->and(config('horizon.defaults.v5reconcile.queue'))->toBe('v5-reconcile');
});

it('keeps the v5-reconcile supervisor isolated from the user-facing deploy queues', function () {
    $reconcileQueues = horizonSupervisorQueues(config('horizon.defaults.v5reconcile.queue'));

    expect($reconcileQueues)->not->toContain('high')
        ->and($reconcileQueues)->not->toContain('default')
        ->and(config('horizon.defaults.v5reconcile.nice'))->toBeGreaterThan(0);
});
