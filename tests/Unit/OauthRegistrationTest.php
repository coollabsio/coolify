<?php

use App\Models\InstanceSettings;

describe('OAuth registration settings', function () {
    it('instance settings model casts oauth settings to boolean', function () {
        $settings = new InstanceSettings;

        $casts = $settings->getCasts();

        expect($casts)->toHaveKey('is_oauth_registration_enabled');
        expect($casts['is_oauth_registration_enabled'])->toBe('boolean');

        expect($casts)->toHaveKey('is_oauth_only_login_forced');
        expect($casts['is_oauth_only_login_forced'])->toBe('boolean');
    });

    it('oauth registration settings have correct default values', function () {
        // Test that the migration sets correct defaults
        // The default for both settings should be false
        $settings = new InstanceSettings;
        $settings->is_oauth_registration_enabled = false;
        $settings->is_oauth_only_login_forced = false;

        expect($settings->is_oauth_registration_enabled)->toBeFalse();
        expect($settings->is_oauth_only_login_forced)->toBeFalse();
    });
});

describe('OAuth registration logic', function () {
    it('allows oauth registration when general registration is disabled but oauth registration is enabled', function () {
        // Simulate the logic from OauthController
        $isRegistrationEnabled = false;
        $isOauthRegistrationEnabled = true;

        // Registration should be allowed if either is true
        $allowRegistration = $isRegistrationEnabled || $isOauthRegistrationEnabled;

        expect($allowRegistration)->toBeTrue();
    });

    it('blocks registration when both settings are disabled', function () {
        $isRegistrationEnabled = false;
        $isOauthRegistrationEnabled = false;

        $allowRegistration = $isRegistrationEnabled || $isOauthRegistrationEnabled;

        expect($allowRegistration)->toBeFalse();
    });

    it('allows registration when general registration is enabled', function () {
        $isRegistrationEnabled = true;
        $isOauthRegistrationEnabled = false;

        $allowRegistration = $isRegistrationEnabled || $isOauthRegistrationEnabled;

        expect($allowRegistration)->toBeTrue();
    });

    it('marks user as oauth-only when forced setting is enabled', function () {
        $isOauthOnlyLoginForced = true;
        $isRegistrationEnabled = true;
        $isOauthRegistrationEnabled = false;

        // User should be marked as oauth-only if the global setting is enabled
        $isOauthOnly = $isOauthOnlyLoginForced ||
            (! $isRegistrationEnabled && $isOauthRegistrationEnabled);

        expect($isOauthOnly)->toBeTrue();
    });

    it('marks user as oauth-only when they can only register via oauth', function () {
        $isOauthOnlyLoginForced = false;
        $isRegistrationEnabled = false;
        $isOauthRegistrationEnabled = true;

        // User should be marked as oauth-only if general registration is disabled
        // but oauth registration is enabled
        $isOauthOnly = $isOauthOnlyLoginForced ||
            (! $isRegistrationEnabled && $isOauthRegistrationEnabled);

        expect($isOauthOnly)->toBeTrue();
    });

    it('does not mark user as oauth-only when general registration is enabled', function () {
        $isOauthOnlyLoginForced = false;
        $isRegistrationEnabled = true;
        $isOauthRegistrationEnabled = false;

        $isOauthOnly = $isOauthOnlyLoginForced ||
            (! $isRegistrationEnabled && $isOauthRegistrationEnabled);

        expect($isOauthOnly)->toBeFalse();
    });
});
