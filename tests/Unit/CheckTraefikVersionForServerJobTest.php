<?php

use App\Jobs\CheckTraefikVersionForServerJob;
use App\Models\Server;

beforeEach(function () {
    $this->traefikVersions = [
        'v3.5' => '3.5.6',
        'v3.6' => '3.6.2',
    ];
});

it('has correct queue and retry configuration', function () {
    $server = \Mockery::mock(Server::class)->makePartial();
    $job = new CheckTraefikVersionForServerJob($server, $this->traefikVersions);

    expect($job->tries)->toBe(3);
    expect($job->timeout)->toBe(60);
    expect($job->server)->toBe($server);
    expect($job->traefikVersions)->toBe($this->traefikVersions);
});

it('parses version strings correctly', function () {
    $version = 'v3.5.0';
    $current = ltrim($version, 'v');

    expect($current)->toBe('3.5.0');

    preg_match('/^(\d+\.\d+)\.(\d+)$/', $current, $matches);

    expect($matches[1])->toBe('3.5'); // branch
    expect($matches[2])->toBe('0'); // patch
});

it('compares versions correctly for patch updates', function () {
    $current = '3.5.0';
    $latest = '3.5.6';

    $isOutdated = version_compare($current, $latest, '<');

    expect($isOutdated)->toBeTrue();
});

it('compares versions correctly for minor upgrades', function () {
    $current = '3.5.6';
    $latest = '3.6.2';

    $isOutdated = version_compare($current, $latest, '<');

    expect($isOutdated)->toBeTrue();
});

it('identifies up-to-date versions', function () {
    $current = '3.6.2';
    $latest = '3.6.2';

    $isUpToDate = version_compare($current, $latest, '=');

    expect($isUpToDate)->toBeTrue();
});

it('identifies newer branch from version map', function () {
    $versions = [
        'v3.5' => '3.5.6',
        'v3.6' => '3.6.2',
        'v3.7' => '3.7.0',
    ];

    $currentBranch = '3.5';
    $newestVersion = null;

    foreach ($versions as $branch => $version) {
        $branchNum = ltrim($branch, 'v');
        if (version_compare($branchNum, $currentBranch, '>')) {
            if (! $newestVersion || version_compare($version, $newestVersion, '>')) {
                $newestVersion = $version;
            }
        }
    }

    expect($newestVersion)->toBe('3.7.0');
});

it('validates version format regex', function () {
    $validVersions = ['3.5.0', '3.6.12', '10.0.1'];
    $invalidVersions = ['3.5', 'v3.5.0', '3.5.0-beta', 'latest'];

    foreach ($validVersions as $version) {
        $matches = preg_match('/^(\d+\.\d+)\.(\d+)$/', $version);
        expect($matches)->toBe(1);
    }

    foreach ($invalidVersions as $version) {
        $matches = preg_match('/^(\d+\.\d+)\.(\d+)$/', $version);
        expect($matches)->toBe(0);
    }
});

it('handles invalid version format gracefully', function () {
    $invalidVersion = 'latest';
    $result = preg_match('/^(\d+\.\d+)\.(\d+)$/', $invalidVersion, $matches);

    expect($result)->toBe(0);
    expect($matches)->toBeEmpty();
});

it('handles empty image tag correctly', function () {
    // Test that empty string after trim doesn't cause issues with str_contains
    $emptyImageTag = '';
    $trimmed = trim($emptyImageTag);

    // This should be false, not an error
    expect(empty($trimmed))->toBeTrue();

    // Test with whitespace only
    $whitespaceTag = "   \n  ";
    $trimmed = trim($whitespaceTag);
    expect(empty($trimmed))->toBeTrue();
});

it('detects latest tag in image name', function () {
    // Test various formats where :latest appears
    $testCases = [
        'traefik:latest' => true,
        'traefik:Latest' => true,
        'traefik:LATEST' => true,
        'traefik:v3.6.0' => false,
        'traefik:3.6.0' => false,
        '' => false,
    ];

    foreach ($testCases as $imageTag => $expected) {
        if (empty(trim($imageTag))) {
            $result = false; // Should return false for empty tags
        } else {
            $result = str_contains(strtolower(trim($imageTag)), ':latest');
        }

        expect($result)->toBe($expected, "Failed for imageTag: '{$imageTag}'");
    }
});
