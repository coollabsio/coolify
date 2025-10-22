<?php

/**
 * Tests for parseHealthcheckFromDockerfile method
 *
 * These tests verify the logic for detecting HEALTHCHECK directives in Dockerfiles,
 * properly ignoring commented lines.
 */
it('detects when HEALTHCHECK is removed from dockerfile', function () {
    $dockerfile = "FROM nginx:latest\nCOPY . /app\nEXPOSE 80";

    $hasHealthcheck = hasUncommentedHealthcheck($dockerfile);
    $customHealthcheckFound = true;
    $shouldReset = ! $hasHealthcheck && $customHealthcheckFound;

    expect($shouldReset)->toBeTrue()
        ->and($hasHealthcheck)->toBeFalse();
});

it('detects uncommented HEALTHCHECK in dockerfile', function () {
    $dockerfile = "FROM nginx:latest\nHEALTHCHECK --interval=30s CMD curl\nEXPOSE 80";

    $hasHealthcheck = hasUncommentedHealthcheck($dockerfile);

    expect($hasHealthcheck)->toBeTrue();
});

it('ignores commented HEALTHCHECK in dockerfile', function () {
    $dockerfile = "FROM nginx:latest\n# HEALTHCHECK --interval=30s CMD curl\nEXPOSE 80";

    $hasHealthcheck = hasUncommentedHealthcheck($dockerfile);

    expect($hasHealthcheck)->toBeFalse();
});

it('detects HEALTHCHECK even with surrounding whitespace', function () {
    $dockerfile = "FROM nginx:latest\n   HEALTHCHECK --interval=30s CMD curl\nEXPOSE 80";

    $hasHealthcheck = hasUncommentedHealthcheck($dockerfile);

    expect($hasHealthcheck)->toBeTrue();
});

it('ignores HEALTHCHECK in middle of line (must be at start)', function () {
    $dockerfile = "FROM nginx:latest\nRUN echo HEALTHCHECK\nEXPOSE 80";

    $hasHealthcheck = hasUncommentedHealthcheck($dockerfile);

    expect($hasHealthcheck)->toBeFalse();
});

it('detects HEALTHCHECK when commented out then uncommented', function () {
    $dockerfileCommented = "FROM nginx:latest\n# HEALTHCHECK --interval=30s CMD curl\nEXPOSE 80";
    $dockerfileUncommented = "FROM nginx:latest\nHEALTHCHECK --interval=30s CMD curl\nEXPOSE 80";

    $hasHealthcheckCommented = hasUncommentedHealthcheck($dockerfileCommented);
    $hasHealthcheckUncommented = hasUncommentedHealthcheck($dockerfileUncommented);

    expect($hasHealthcheckCommented)->toBeFalse()
        ->and($hasHealthcheckUncommented)->toBeTrue();
});

// Helper function that mimics the logic in parseHealthcheckFromDockerfile
function hasUncommentedHealthcheck(string $dockerfile): bool
{
    $lines = str($dockerfile)->trim()->explode("\n");

    foreach ($lines as $line) {
        $trimmedLine = trim($line);
        // Skip empty lines and comments
        if (empty($trimmedLine) || str_starts_with($trimmedLine, '#')) {
            continue;
        }
        // Check if line starts with HEALTHCHECK (not commented)
        if (str_starts_with($trimmedLine, 'HEALTHCHECK')) {
            return true;
        }
    }

    return false;
}
