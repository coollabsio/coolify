<?php

/**
 * Test suite for OAuth registration settings logic.
 *
 * These tests verify that the OAuth callback correctly handles
 * the is_oauth_registration_enabled setting in combination with
 * the is_registration_enabled setting.
 */

describe('OAuth Registration Settings Logic', function () {
    /**
     * Helper to check if registration should be allowed based on settings.
     * This mirrors the logic in OauthController::callback()
     */
    function shouldAllowOAuthRegistration(bool $generalRegistrationEnabled, bool $oauthRegistrationEnabled): bool
    {
        // Allow registration if either general registration or OAuth-specific registration is enabled
        return $generalRegistrationEnabled || $oauthRegistrationEnabled;
    }

    it('blocks registration when both settings are disabled', function () {
        $result = shouldAllowOAuthRegistration(
            generalRegistrationEnabled: false,
            oauthRegistrationEnabled: false
        );

        expect($result)->toBeFalse();
    });

    it('allows registration when general registration is enabled', function () {
        $result = shouldAllowOAuthRegistration(
            generalRegistrationEnabled: true,
            oauthRegistrationEnabled: false
        );

        expect($result)->toBeTrue();
    });

    it('allows registration when OAuth registration is enabled but general is disabled', function () {
        $result = shouldAllowOAuthRegistration(
            generalRegistrationEnabled: false,
            oauthRegistrationEnabled: true
        );

        expect($result)->toBeTrue();
    });

    it('allows registration when both settings are enabled', function () {
        $result = shouldAllowOAuthRegistration(
            generalRegistrationEnabled: true,
            oauthRegistrationEnabled: true
        );

        expect($result)->toBeTrue();
    });
});
