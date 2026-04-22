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
    $setting->is_static = true;

    // Verify it's cast to boolean
    expect($setting->is_static)->toBeTrue()
        ->and($setting->is_static)->toBeBool();
});

it('casts is_static to boolean when false', function () {
    $setting = new ApplicationSetting;
    $setting->is_static = false;

    // Verify it's cast to boolean
    expect($setting->is_static)->toBeFalse()
        ->and($setting->is_static)->toBeBool();
});

it('casts is_static from string "1" to boolean true', function () {
    $setting = new ApplicationSetting;
    $setting->is_static = '1';

    // Should cast string to boolean
    expect($setting->is_static)->toBeTrue()
        ->and($setting->is_static)->toBeBool();
});

it('casts is_static from string "0" to boolean false', function () {
    $setting = new ApplicationSetting;
    $setting->is_static = '0';

    // Should cast string to boolean
    expect($setting->is_static)->toBeFalse()
        ->and($setting->is_static)->toBeBool();
});

it('casts is_static from integer 1 to boolean true', function () {
    $setting = new ApplicationSetting;
    $setting->is_static = 1;

    // Should cast integer to boolean
    expect($setting->is_static)->toBeTrue()
        ->and($setting->is_static)->toBeBool();
});

it('casts is_static from integer 0 to boolean false', function () {
    $setting = new ApplicationSetting;
    $setting->is_static = 0;

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
    // Value of 0 is not a valid grace period — consumers guard with
    // `($value > 0) ? $value : DEFAULT_STOP_GRACE_PERIOD_SECONDS`, so
    // the cast itself must still round-trip cleanly without throwing.
    $setting = new ApplicationSetting;
    $setting->stop_grace_period = 0;

    expect($setting->stop_grace_period)->toBe(0)
        ->and($setting->stop_grace_period)->toBeInt();
});

it('casts stop_grace_period negative value to integer (documents fallback trigger)', function () {
    // Negative values should never be persisted (UI validates `min=1`),
    // but if one slips through (direct DB write, older data), the cast
    // must not throw and consumers will treat it as the fallback via the
    // `> 0` guard.
    $setting = new ApplicationSetting;
    $setting->stop_grace_period = -10;

    expect($setting->stop_grace_period)->toBe(-10)
        ->and($setting->stop_grace_period)->toBeInt();
});
