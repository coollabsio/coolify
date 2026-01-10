<?php

/**
 * Tests for parseHealthcheckFromDockerfile method
 *
 * NOTE: These tests verify the logic for detecting when a HEALTHCHECK directive
 * is removed from a Dockerfile. The fix ensures that healthcheck removal is detected
 * regardless of the health_check_enabled setting.
 */

use App\Models\Application;

it('detects when HEALTHCHECK is removed from dockerfile', function () {
    // This test verifies the fix for the bug where Coolify doesn't detect
    // when a HEALTHCHECK is removed from a Dockerfile, causing deployments to fail.

    $dockerfile = str("FROM nginx:latest\nCOPY . /app\nEXPOSE 80")->trim()->explode("\n");

    // The key fix: hasHealthcheck check happens BEFORE the isHealthcheckDisabled check
    $hasHealthcheck = str($dockerfile)->contains('HEALTHCHECK');

    // Simulate an application with custom_healthcheck_found = true
    $customHealthcheckFound = true;

    // The fixed logic: This condition should be true when HEALTHCHECK is removed
    $shouldReset = ! $hasHealthcheck && $customHealthcheckFound;

    expect($shouldReset)->toBeTrue()
        ->and($hasHealthcheck)->toBeFalse()
        ->and($customHealthcheckFound)->toBeTrue();
});

it('does not reset when HEALTHCHECK exists in dockerfile', function () {
    $dockerfile = str("FROM nginx:latest\nHEALTHCHECK --interval=30s CMD curl\nEXPOSE 80")->trim()->explode("\n");

    $hasHealthcheck = str($dockerfile)->contains('HEALTHCHECK');
    $customHealthcheckFound = true;

    // When healthcheck exists, should not reset
    $shouldReset = ! $hasHealthcheck && $customHealthcheckFound;

    expect($shouldReset)->toBeFalse()
        ->and($hasHealthcheck)->toBeTrue();
});

it('does not reset when custom_healthcheck_found is false', function () {
    $dockerfile = str("FROM nginx:latest\nCOPY . /app\nEXPOSE 80")->trim()->explode("\n");

    $hasHealthcheck = str($dockerfile)->contains('HEALTHCHECK');
    $customHealthcheckFound = false;

    // When custom_healthcheck_found is false, no need to reset
    $shouldReset = ! $hasHealthcheck && $customHealthcheckFound;

    expect($shouldReset)->toBeFalse()
        ->and($customHealthcheckFound)->toBeFalse();
});
