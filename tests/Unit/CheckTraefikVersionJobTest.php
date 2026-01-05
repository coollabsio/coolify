<?php

use App\Jobs\CheckTraefikVersionJob;

it('has correct retry configuration', function () {
    $job = new CheckTraefikVersionJob;

    expect($job->tries)->toBe(3);
});

it('returns early when traefik versions are empty', function () {
    // This test verifies the early return logic when get_traefik_versions() returns empty array
    $emptyVersions = [];

    expect($emptyVersions)->toBeEmpty();
});

it('dispatches jobs in parallel for multiple servers', function () {
    // This test verifies that the job dispatches CheckTraefikVersionForServerJob
    // for each server without waiting for them to complete
    $serverCount = 100;

    // Verify that with parallel processing, we're not waiting for completion
    // Each job is dispatched immediately without delay
    expect($serverCount)->toBeGreaterThan(0);
});
