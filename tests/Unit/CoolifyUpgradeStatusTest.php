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

it('checks the running version through authenticated upgrade status before showing reload', function () {
    $upgradeView = file_get_contents(__DIR__.'/../../resources/views/livewire/upgrade.blade.php');

    expect($upgradeView)
        ->not->toContain('X-Coolify-Version')
        ->toContain('this.$wire.getUpgradeStatus()')
        ->toContain("data.status === 'complete'")
        ->toContain('livewireFailures');
});

it('uses the public health endpoint only for liveness during an upgrade', function () {
    $upgradeView = file_get_contents(__DIR__.'/../../resources/views/livewire/upgrade.blade.php');

    expect($upgradeView)
        ->toContain('instanceWentDown')
        ->toContain('startHealthWatch')
        ->toContain("fetch('/api/health')")
        ->not->toContain('response.headers.get');
});

it('finishes after health recovers from observed downtime for older target releases', function () {
    $upgradeView = file_get_contents(__DIR__.'/../../resources/views/livewire/upgrade.blade.php');

    expect($upgradeView)
        ->toContain("data.status === 'none' && this.instanceWentDown")
        ->toContain('if (this.instanceWentDown) {')
        ->toContain('this.showSuccess();')
        ->toContain('return;');
});

it('finishes when the post-restart Livewire status response is undefined', function () {
    $upgradeView = file_get_contents(__DIR__.'/../../resources/views/livewire/upgrade.blade.php');

    expect($upgradeView)
        ->toContain('if (!data) {')
        ->toContain('if (this.instanceWentDown) {')
        ->toContain('this.showSuccess();')
        ->toContain('return;');
});

it('tells users to reload manually if the automatic reload does not happen', function () {
    $upgradeView = file_get_contents(__DIR__.'/../../resources/views/livewire/upgrade.blade.php');

    expect($upgradeView)->toContain('If the page does not reload automatically, reload it manually.');
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
