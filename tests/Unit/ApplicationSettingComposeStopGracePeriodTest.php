<?php

/**
 * Tests for ApplicationSetting::composeStopGracePeriod
 *
 * NOTE: These tests verify the compose duration emitted for the generated
 * docker-compose service. When stop_grace_period is not configured, no value
 * is emitted so the container keeps Docker's default behavior; when it is
 * configured, the effective (validated) value is emitted as a duration string.
 */

use App\Models\ApplicationSetting;

it('returns null when stop_grace_period is not configured', function () {
    $setting = new ApplicationSetting;
    $setting->setRawAttributes(['stop_grace_period' => null]);

    expect($setting->composeStopGracePeriod())->toBeNull();
});

it('returns the configured value as a compose duration string', function () {
    $setting = new ApplicationSetting;
    $setting->setRawAttributes(['stop_grace_period' => 700]);

    expect($setting->composeStopGracePeriod())->toBe('700s');
});

it('returns the minimum allowed value as a compose duration string', function () {
    $setting = new ApplicationSetting;
    $setting->setRawAttributes(['stop_grace_period' => MIN_STOP_GRACE_PERIOD_SECONDS]);

    expect($setting->composeStopGracePeriod())->toBe(MIN_STOP_GRACE_PERIOD_SECONDS.'s');
});

it('returns the maximum allowed value as a compose duration string', function () {
    $setting = new ApplicationSetting;
    $setting->setRawAttributes(['stop_grace_period' => MAX_STOP_GRACE_PERIOD_SECONDS]);

    expect($setting->composeStopGracePeriod())->toBe(MAX_STOP_GRACE_PERIOD_SECONDS.'s');
});

it('falls back to the default effective value for an out-of-range configured value', function () {
    $setting = new ApplicationSetting;
    $setting->setRawAttributes(['stop_grace_period' => MAX_STOP_GRACE_PERIOD_SECONDS + 1]);

    expect($setting->composeStopGracePeriod())->toBe(DEFAULT_STOP_GRACE_PERIOD_SECONDS.'s');
});
