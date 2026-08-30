<?php

use App\Services\RestartCountTracker;

it('starts a new generation when an active container restart count drops', function () {
    $result = (new RestartCountTracker)->evaluate(
        previousRestartCount: 11,
        observedRestartCount: 0,
        maxRestartCount: 2,
        containerIsActive: true,
    );

    expect($result)->toMatchArray([
        'restart_count' => 0,
        'restart_count_changed' => true,
        'restart_limit_reached' => false,
        'new_generation' => true,
    ]);
});

it('evaluates the restart limit immediately in a new generation', function () {
    $result = (new RestartCountTracker)->evaluate(
        previousRestartCount: 11,
        observedRestartCount: 3,
        maxRestartCount: 2,
        containerIsActive: true,
    );

    expect($result)->toMatchArray([
        'restart_count' => 3,
        'restart_count_changed' => true,
        'restart_limit_reached' => true,
        'new_generation' => true,
    ]);
});

it('does not reset the generation while the container remains exited', function () {
    $result = (new RestartCountTracker)->evaluate(
        previousRestartCount: 11,
        observedRestartCount: 0,
        maxRestartCount: 2,
        containerIsActive: false,
    );

    expect($result)->toMatchArray([
        'restart_count' => 11,
        'restart_count_changed' => false,
        'restart_limit_reached' => false,
        'new_generation' => false,
    ]);
});

it('detects a normal threshold crossing', function () {
    $result = (new RestartCountTracker)->evaluate(
        previousRestartCount: 1,
        observedRestartCount: 2,
        maxRestartCount: 2,
        containerIsActive: true,
    );

    expect($result)->toMatchArray([
        'restart_count' => 2,
        'restart_count_changed' => true,
        'restart_limit_reached' => true,
        'new_generation' => false,
    ]);
});
