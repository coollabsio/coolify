<?php

/**
 * Tests for ApplicationSetting model boolean casting
 *
 * NOTE: These tests verify that the is_static field properly casts to boolean.
 * The fix changes $cast to $casts to enable proper Laravel boolean casting.
 */

use App\Models\ApplicationSetting;

it('casts is_static to boolean when true', function () {
    $setting = new ApplicationSetting;
    $setting->setRawAttributes(['is_static' => true]);

    // Verify it's cast to boolean
    expect($setting->is_static)->toBeTrue()
        ->and($setting->is_static)->toBeBool();
});

it('casts is_static to boolean when false', function () {
    $setting = new ApplicationSetting;
    $setting->setRawAttributes(['is_static' => false]);

    // Verify it's cast to boolean
    expect($setting->is_static)->toBeFalse()
        ->and($setting->is_static)->toBeBool();
});

it('casts is_static from string "1" to boolean true', function () {
    $setting = new ApplicationSetting;
    $setting->setRawAttributes(['is_static' => '1']);

    // Should cast string to boolean
    expect($setting->is_static)->toBeTrue()
        ->and($setting->is_static)->toBeBool();
});

it('casts is_static from string "0" to boolean false', function () {
    $setting = new ApplicationSetting;
    $setting->setRawAttributes(['is_static' => '0']);

    // Should cast string to boolean
    expect($setting->is_static)->toBeFalse()
        ->and($setting->is_static)->toBeBool();
});

it('casts is_static from integer 1 to boolean true', function () {
    $setting = new ApplicationSetting;
    $setting->setRawAttributes(['is_static' => 1]);

    // Should cast integer to boolean
    expect($setting->is_static)->toBeTrue()
        ->and($setting->is_static)->toBeBool();
});

it('casts is_static from integer 0 to boolean false', function () {
    $setting = new ApplicationSetting;
    $setting->setRawAttributes(['is_static' => 0]);

    // Should cast integer to boolean
    expect($setting->is_static)->toBeFalse()
        ->and($setting->is_static)->toBeBool();
});

it('has casts array property defined correctly', function () {
    $setting = new ApplicationSetting;

    // Verify the casts property exists and is configured
    $casts = $setting->getCasts();

    expect($casts)->toHaveKey('is_static')
        ->and($casts['is_static'])->toBe('boolean');
});

it('casts all boolean fields correctly', function () {
    $setting = new ApplicationSetting;

    // Get all casts
    $casts = $setting->getCasts();

    // Verify all expected boolean fields are cast
    $expectedBooleanCasts = [
        'is_static',
        'is_spa',
        'is_build_server_enabled',
        'is_preserve_repository_enabled',
        'is_container_label_escape_enabled',
        'is_container_label_readonly_enabled',
        'use_build_secrets',
        'is_auto_deploy_enabled',
        'is_force_https_enabled',
        'is_debug_enabled',
        'is_preview_deployments_enabled',
        'is_pr_deployments_public_enabled',
        'is_git_submodules_enabled',
        'is_git_lfs_enabled',
        'is_git_shallow_clone_enabled',
    ];

    foreach ($expectedBooleanCasts as $field) {
        expect($casts)->toHaveKey($field)
            ->and($casts[$field])->toBe('boolean');
    }
});

it('casts stop_grace_period to integer', function () {
    $setting = new ApplicationSetting;
    $casts = $setting->getCasts();

    expect($casts)->toHaveKey('stop_grace_period')
        ->and($casts['stop_grace_period'])->toBe('integer');
});

it('handles null stop_grace_period for default behavior', function () {
    $setting = new ApplicationSetting;
    $setting->stop_grace_period = null;

    expect($setting->stop_grace_period)->toBeNull();
});

it('casts stop_grace_period from string to integer', function () {
    $setting = new ApplicationSetting;
    $setting->stop_grace_period = '60';

    expect($setting->stop_grace_period)->toBe(60)
        ->and($setting->stop_grace_period)->toBeInt();
});

it('casts stop_grace_period zero to integer (documents fallback trigger)', function () {
    $setting = new ApplicationSetting;
    $setting->stop_grace_period = 0;

    expect($setting->stop_grace_period)->toBe(0)
        ->and($setting->stop_grace_period)->toBeInt();
});

it('casts stop_grace_period negative value to integer (documents fallback trigger)', function () {
    $setting = new ApplicationSetting;
    $setting->stop_grace_period = -10;

    expect($setting->stop_grace_period)->toBe(-10)
        ->and($setting->stop_grace_period)->toBeInt();
});

it('resolves valid stop grace periods', function (?int $storedValue, int $expectedValue) {
    $setting = new ApplicationSetting;
    $setting->stop_grace_period = $storedValue;

    expect($setting->stopGracePeriodSeconds())->toBe($expectedValue);
})->with([
    'minimum' => [MIN_STOP_GRACE_PERIOD_SECONDS, MIN_STOP_GRACE_PERIOD_SECONDS],
    'custom' => [300, 300],
    'maximum' => [MAX_STOP_GRACE_PERIOD_SECONDS, MAX_STOP_GRACE_PERIOD_SECONDS],
]);

it('falls back to default stop grace period for invalid stored values', function (?int $storedValue) {
    $setting = new ApplicationSetting;
    $setting->stop_grace_period = $storedValue;

    expect($setting->stopGracePeriodSeconds())->toBe(DEFAULT_STOP_GRACE_PERIOD_SECONDS);
})->with([
    'null' => [null],
    'zero' => [0],
    'negative' => [-10],
    'above maximum' => [MAX_STOP_GRACE_PERIOD_SECONDS + 1],
]);
