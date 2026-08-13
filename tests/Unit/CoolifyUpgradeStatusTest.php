<?php

use App\Services\CoolifyUpgradeStatus;
use Carbon\Carbon;

it('treats empty or malformed status as none', function (?string $content) {
    expect(CoolifyUpgradeStatus::fromFile(
        content: $content ?? '',
        runningVersion: '4.3.0',
        targetVersion: '4.3.1',
    ))->toMatchArray([
        'status' => 'none',
        'running_version' => '4.3.0',
        'target_version' => '4.3.1',
    ]);
})->with([
    'empty' => '',
    'whitespace' => '   ',
    'missing fields' => '6|Upgrade complete',
    'single token' => 'complete',
]);

it('returns in_progress for intermediate upgrade steps', function () {
    $content = '4|Stopping containers|'.Carbon::parse('2026-08-13T12:00:00+00:00')->toIso8601String();

    $result = CoolifyUpgradeStatus::fromFile(
        content: $content,
        runningVersion: '4.3.0',
        targetVersion: '4.3.1',
        now: Carbon::parse('2026-08-13T12:01:00+00:00'),
    );

    expect($result)->toMatchArray([
        'status' => 'in_progress',
        'step' => 4,
        'message' => 'Stopping containers',
        'running_version' => '4.3.0',
        'target_version' => '4.3.1',
    ]);
});

it('does not mark the upgrade complete when the script finished but the running version is still old', function () {
    $content = '6|Upgrade complete|'.Carbon::parse('2026-08-13T12:00:00+00:00')->toIso8601String();

    $result = CoolifyUpgradeStatus::fromFile(
        content: $content,
        runningVersion: '4.3.0',
        targetVersion: '4.3.1',
        now: Carbon::parse('2026-08-13T12:01:00+00:00'),
    );

    expect($result['status'])->toBe('in_progress')
        ->and($result['step'])->toBe(6)
        ->and($result['running_version'])->toBe('4.3.0')
        ->and($result['target_version'])->toBe('4.3.1')
        ->and($result['message'])->toContain('Waiting for Coolify')
        ->and($result['message'])->toContain('4.3.1');
});

it('marks the upgrade complete only after the running version matches the target', function () {
    $content = '6|Upgrade complete|'.Carbon::parse('2026-08-13T12:00:00+00:00')->toIso8601String();

    $result = CoolifyUpgradeStatus::fromFile(
        content: $content,
        runningVersion: '4.3.1',
        targetVersion: '4.3.1',
        now: Carbon::parse('2026-08-13T12:01:00+00:00'),
    );

    expect($result)->toMatchArray([
        'status' => 'complete',
        'step' => 6,
        'message' => 'Upgrade complete',
        'running_version' => '4.3.1',
        'target_version' => '4.3.1',
    ]);
});

it('treats a running version newer than the target as complete', function () {
    $content = '6|Upgrade complete|'.Carbon::parse('2026-08-13T12:00:00+00:00')->toIso8601String();

    $result = CoolifyUpgradeStatus::fromFile(
        content: $content,
        runningVersion: '4.3.2',
        targetVersion: '4.3.1',
        now: Carbon::parse('2026-08-13T12:01:00+00:00'),
    );

    expect($result['status'])->toBe('complete');
});

it('returns error status without requiring a version match', function () {
    $content = 'error|Failed to pull image|'.Carbon::parse('2026-08-13T12:00:00+00:00')->toIso8601String();

    $result = CoolifyUpgradeStatus::fromFile(
        content: $content,
        runningVersion: '4.3.0',
        targetVersion: '4.3.1',
        now: Carbon::parse('2026-08-13T12:01:00+00:00'),
    );

    expect($result)->toMatchArray([
        'status' => 'error',
        'step' => 0,
        'message' => 'Failed to pull image',
    ]);
});

it('ignores stale status files older than ten minutes', function () {
    $content = '6|Upgrade complete|'.Carbon::parse('2026-08-13T11:00:00+00:00')->toIso8601String();

    $result = CoolifyUpgradeStatus::fromFile(
        content: $content,
        runningVersion: '4.3.1',
        targetVersion: '4.3.1',
        now: Carbon::parse('2026-08-13T12:00:00+00:00'),
    );

    expect($result['status'])->toBe('none');
});

it('waits for the running version to match the target before showing reload', function () {
    $upgradeView = file_get_contents(__DIR__.'/../../resources/views/livewire/upgrade.blade.php');

    expect($upgradeView)
        ->toContain('X-Coolify-Version')
        ->toContain('hasReachedTargetVersion')
        ->toContain('livewireFailures');
});

it('treats a healthy instance without a version header as ready only after downtime', function () {
    $upgradeView = file_get_contents(__DIR__.'/../../resources/views/livewire/upgrade.blade.php');

    expect($upgradeView)
        ->toContain('instanceWentDown')
        ->toContain('isReadyToReload')
        ->toContain('startHealthWatch')
        ->toContain('data.status === \'none\'');
});

it('starts the upgrade after the Livewire response so status polling is not blocked', function () {
    $upgradeComponent = file_get_contents(__DIR__.'/../../app/Livewire/Upgrade.php');

    expect($upgradeComponent)
        ->toContain('afterResponse()')
        ->toContain('CoolifyUpgradeStatus::fromFile');
});

it('reports whether the running version has reached the target', function (string $running, string $target, bool $reached) {
    expect(CoolifyUpgradeStatus::hasReachedTargetVersion($running, $target))->toBe($reached);
})->with([
    'old instance still serving' => ['4.3.0', '4.3.1', false],
    'target reached' => ['4.3.1', '4.3.1', true],
    'already newer' => ['4.3.2', '4.3.1', true],
    'missing running version' => ['', '4.3.1', false],
    'missing target version' => ['4.3.1', '', false],
]);
