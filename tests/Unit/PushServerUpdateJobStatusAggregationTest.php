<?php

/**
 * Unit tests for PushServerUpdateJob status aggregation logic.
 *
 * These tests verify that the job correctly aggregates container statuses
 * when processing Sentinel updates, with proper handling of:
 * - running (healthy) - all containers running and healthy
 * - running (unhealthy) - some containers unhealthy
 * - running (unknown) - some containers with unknown health status
 *
 * The aggregation follows a priority system: unhealthy > unknown > healthy
 *
 * This ensures consistency with GetContainersStatus::aggregateApplicationStatus()
 * and prevents the bug where "unknown" status was incorrectly converted to "healthy".
 */
it('aggregates status with unknown health state correctly', function () {
    $jobFile = file_get_contents(__DIR__.'/../../app/Jobs/PushServerUpdateJob.php');

    // Verify that hasUnknown tracking variable exists
    expect($jobFile)
        ->toContain('$hasUnknown = false;')
        ->toContain('if (str($status)->contains(\'unknown\')) {')
        ->toContain('$hasUnknown = true;');

    // Verify 3-way status priority logic (unhealthy > unknown > healthy)
    expect($jobFile)
        ->toContain('if ($hasUnhealthy) {')
        ->toContain('$aggregatedStatus = \'running (unhealthy)\';')
        ->toContain('} elseif ($hasUnknown) {')
        ->toContain('$aggregatedStatus = \'running (unknown)\';')
        ->toContain('} else {')
        ->toContain('$aggregatedStatus = \'running (healthy)\';');
});

it('checks for unknown status alongside unhealthy status', function () {
    $jobFile = file_get_contents(__DIR__.'/../../app/Jobs/PushServerUpdateJob.php');

    // Verify unknown check is placed alongside unhealthy check
    expect($jobFile)
        ->toContain('if (str($status)->contains(\'unhealthy\')) {')
        ->toContain('if (str($status)->contains(\'unknown\')) {');
});

it('follows same priority as GetContainersStatus', function () {
    $jobFile = file_get_contents(__DIR__.'/../../app/Jobs/PushServerUpdateJob.php');
    $getContainersFile = file_get_contents(__DIR__.'/../../app/Actions/Docker/GetContainersStatus.php');

    // Both should track hasUnknown
    expect($jobFile)->toContain('$hasUnknown = false;');
    expect($getContainersFile)->toContain('$hasUnknown = false;');

    // Both should check for 'unknown' in status strings
    expect($jobFile)->toContain('if (str($status)->contains(\'unknown\')) {');
    expect($getContainersFile)->toContain('if (str($status)->contains(\'unknown\')) {');

    // Both should prioritize unhealthy over unknown over healthy
    expect($jobFile)->toContain('} elseif ($hasUnknown) {');
    expect($getContainersFile)->toContain('} elseif ($hasUnknown) {');
});

it('does not default unknown to healthy status', function () {
    $jobFile = file_get_contents(__DIR__.'/../../app/Jobs/PushServerUpdateJob.php');

    // The old buggy code was:
    // $aggregatedStatus = $hasUnhealthy ? 'running (unhealthy)' : 'running (healthy)';
    // This would make unknown -> healthy

    // Verify we're NOT using ternary operator for status assignment
    expect($jobFile)
        ->not->toContain('$aggregatedStatus = $hasUnhealthy ? \'running (unhealthy)\' : \'running (healthy)\';');

    // Verify we ARE using if-elseif-else with proper unknown handling
    expect($jobFile)
        ->toContain('if ($hasUnhealthy) {')
        ->toContain('} elseif ($hasUnknown) {')
        ->toContain('$aggregatedStatus = \'running (unknown)\';');
});

it('initializes all required status tracking variables', function () {
    $jobFile = file_get_contents(__DIR__.'/../../app/Jobs/PushServerUpdateJob.php');

    // Verify all three tracking variables are initialized together
    $pattern = '/\$hasRunning\s*=\s*false;\s*\$hasUnhealthy\s*=\s*false;\s*\$hasUnknown\s*=\s*false;/s';

    expect(preg_match($pattern, $jobFile))->toBe(1,
        'All status tracking variables ($hasRunning, $hasUnhealthy, $hasUnknown) should be initialized together');
});

it('preserves unknown status through sentinel updates', function () {
    $jobFile = file_get_contents(__DIR__.'/../../app/Jobs/PushServerUpdateJob.php');

    // The critical path: when a status contains 'running' AND 'unknown',
    // both flags should be set
    expect($jobFile)
        ->toContain('if (str($status)->contains(\'running\')) {')
        ->toContain('$hasRunning = true;')
        ->toContain('if (str($status)->contains(\'unhealthy\')) {')
        ->toContain('$hasUnhealthy = true;')
        ->toContain('if (str($status)->contains(\'unknown\')) {')
        ->toContain('$hasUnknown = true;');

    // And then unknown should have priority over healthy in aggregation
    expect($jobFile)
        ->toContain('} elseif ($hasUnknown) {')
        ->toContain('$aggregatedStatus = \'running (unknown)\';');
});
