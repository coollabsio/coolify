<?php

use App\Services\RestartCountTracker;

it('starts a new generation when an active container restart count drops', function () {
    $result = (new RestartCountTracker)->evaluate(
        previousRestartCount: 11,
        observedRestartCount: 0,
        maxRestartCount: 2,
        newGenerationConfirmed: true,
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
        newGenerationConfirmed: true,
    );

    expect($result)->toMatchArray([
        'restart_count' => 3,
        'restart_count_changed' => true,
        'restart_limit_reached' => true,
        'new_generation' => true,
    ]);
});

it('does not reset the generation without explicit confirmation', function () {
    $result = (new RestartCountTracker)->evaluate(
        previousRestartCount: 11,
        observedRestartCount: 0,
        maxRestartCount: 2,
    );

    expect($result)->toMatchArray([
        'restart_count' => 11,
        'restart_count_changed' => false,
        'restart_limit_reached' => false,
        'new_generation' => false,
    ]);
});

it('preserves the previous count when an active payload omits the container with the previous maximum', function () {
    $result = (new RestartCountTracker)->evaluate(
        previousRestartCount: 11,
        observedRestartCount: 3,
        maxRestartCount: 20,
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
    );

    expect($result)->toMatchArray([
        'restart_count' => 2,
        'restart_count_changed' => true,
        'restart_limit_reached' => true,
        'new_generation' => false,
    ]);
});

it('detects a limit that is enabled below the current observed restart count', function () {
    $result = (new RestartCountTracker)->evaluate(
        previousRestartCount: 17,
        observedRestartCount: 17,
        maxRestartCount: 10,
    );

    expect($result)->toMatchArray([
        'restart_count' => 17,
        'restart_count_changed' => false,
        'restart_limit_reached' => true,
        'new_generation' => false,
    ]);
});
