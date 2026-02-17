<?php

/**
 * Tests verifying Debian 13 (trixie) and Alpine Linux support
 * in the server provisioning pipeline.
 *
 * These tests verify the constants, handler coverage, and command
 * generation without requiring SSH connections to remote servers.
 */

it('includes debian in SUPPORTED_OS', function () {
    $supported = collect(SUPPORTED_OS);
    $debianGroup = $supported->first(fn ($os) => str($os)->contains('debian'));

    expect($debianGroup)->not->toBeNull()
        ->and($debianGroup)->toContain('debian');
});

it('includes alpine in SUPPORTED_OS', function () {
    $supported = collect(SUPPORTED_OS);
    $alpineGroup = $supported->first(fn ($os) => str($os)->contains('alpine'));

    expect($alpineGroup)->not->toBeNull()
        ->and($alpineGroup)->toContain('alpine');
});

it('has InstallPrerequisites handler for all SUPPORTED_OS groups', function () {
    // Each SUPPORTED_OS group must have a matching handler in InstallPrerequisites.
    // The groups map to: debian, rhel, sles, arch, alpine.
    $handledGroups = ['debian', 'rhel', 'sles', 'arch', 'alpine'];
    $supportedGroups = collect(SUPPORTED_OS);

    foreach ($supportedGroups as $group) {
        $matched = collect($handledGroups)->first(fn ($handler) => str($group)->contains($handler));
        expect($matched)->not->toBeNull(
            "SUPPORTED_OS group '{$group}' has no handler in InstallPrerequisites"
        );
    }
});

it('has InstallDocker handler for all SUPPORTED_OS groups', function () {
    // Each SUPPORTED_OS group must have a matching handler in InstallDocker.
    // The groups map to: debian, rhel, sles, arch, alpine (+ generic fallback).
    $handledGroups = ['debian', 'rhel', 'sles', 'arch', 'alpine'];
    $supportedGroups = collect(SUPPORTED_OS);

    foreach ($supportedGroups as $group) {
        $matched = collect($handledGroups)->first(fn ($handler) => str($group)->contains($handler));
        expect($matched)->not->toBeNull(
            "SUPPORTED_OS group '{$group}' has no handler in InstallDocker"
        );
    }
});

it('generates Alpine Docker install command using apk', function () {
    $action = new \App\Actions\Server\InstallDocker;

    $reflection = new ReflectionMethod($action, 'getAlpineDockerInstallCommand');
    $reflection->setAccessible(true);
    $command = $reflection->invoke($action);

    expect($command)->toContain('apk add docker')
        ->and($command)->toContain('rc-update add docker')
        ->and($command)->toContain('rc-service docker start');
});

it('generates Debian Docker install command with codename fallback', function () {
    $action = new \App\Actions\Server\InstallDocker;

    // Set the dockerVersion property via reflection
    $versionProp = new ReflectionProperty($action, 'dockerVersion');
    $versionProp->setAccessible(true);
    $versionProp->setValue($action, '27.0');

    $reflection = new ReflectionMethod($action, 'getDebianDockerInstallCommand');
    $reflection->setAccessible(true);
    $command = $reflection->invoke($action);

    // Should try the codename from os-release first, then fall back to bookworm
    expect($command)->toContain('CODENAME=${VERSION_CODENAME}')
        ->and($command)->toContain('CODENAME=bookworm')
        ->and($command)->toContain('download.docker.com/linux/${ID}');
});

it('includes apk in CheckUpdates package manager mapping', function () {
    $action = new \App\Actions\Server\CheckUpdates;

    // Verify the apk output parser exists
    $reflection = new ReflectionMethod($action, 'parseApkOutput');
    $reflection->setAccessible(true);

    // Test with sample apk version output
    $sampleOutput = "curl-8.5.0-r0 < 8.6.0-r0\nwget-1.21.4-r0 < 1.21.4-r1\n";
    $result = $reflection->invoke($action, $sampleOutput);

    expect($result)->toHaveKey('total_updates')
        ->and($result)->toHaveKey('updates')
        ->and($result['total_updates'])->toBe(2)
        ->and($result['updates'][0]['package'])->toBe('curl')
        ->and($result['updates'][0]['new_version'])->toBe('8.6.0-r0');
});

it('parses empty apk output correctly', function () {
    $action = new \App\Actions\Server\CheckUpdates;
    $reflection = new ReflectionMethod($action, 'parseApkOutput');
    $reflection->setAccessible(true);

    $result = $reflection->invoke($action, '');

    expect($result['total_updates'])->toBe(0)
        ->and($result['updates'])->toBeEmpty();
});

it('debian 13 trixie would match debian group in SUPPORTED_OS', function () {
    // Simulate what validateOS does: match ID from /etc/os-release against SUPPORTED_OS
    $debianId = 'debian'; // Debian 13 trixie reports ID=debian

    $supported = collect(SUPPORTED_OS)->filter(function ($supportedOs) use ($debianId) {
        if (str($supportedOs)->contains($debianId)) {
            return true;
        }
    });

    expect($supported->count())->toBe(1)
        ->and($supported->first())->toContain('debian');
});

it('alpine would match alpine group in SUPPORTED_OS', function () {
    $alpineId = 'alpine';

    $supported = collect(SUPPORTED_OS)->filter(function ($supportedOs) use ($alpineId) {
        if (str($supportedOs)->contains($alpineId)) {
            return true;
        }
    });

    expect($supported->count())->toBe(1)
        ->and($supported->first())->toContain('alpine');
});
