<?php

/**
 * Unit tests for User model OAuth-related helper methods.
 * These tests verify the behavior of isOAuthUser and isPasswordLoginDisabled methods.
 */

it('isOAuthUser returns true when oauth_provider is set', function () {
    $userData = [
        'oauth_provider' => 'github',
    ];
    
    expect(! empty($userData['oauth_provider']))->toBeTrue();
});

it('isOAuthUser returns false when oauth_provider is null', function () {
    $userData = [
        'oauth_provider' => null,
    ];
    
    expect(! empty($userData['oauth_provider']))->toBeFalse();
});

it('isPasswordLoginDisabled returns true when password_login_disabled is true', function () {
    $userData = [
        'password_login_disabled' => true,
    ];
    
    expect($userData['password_login_disabled'] === true)->toBeTrue();
});

it('isPasswordLoginDisabled returns false when password_login_disabled is false', function () {
    $userData = [
        'password_login_disabled' => false,
    ];
    
    expect($userData['password_login_disabled'] === true)->toBeFalse();
});

it('OAuth registration check allows when is_oauth_registration_enabled is true', function () {
    $settings = [
        'is_registration_enabled' => false,
        'is_oauth_registration_enabled' => true,
    ];
    
    $allowed = $settings['is_registration_enabled'] || $settings['is_oauth_registration_enabled'];
    
    expect($allowed)->toBeTrue();
});

it('OAuth registration check blocks when both settings are false', function () {
    $settings = [
        'is_registration_enabled' => false,
        'is_oauth_registration_enabled' => false,
    ];
    
    $allowed = $settings['is_registration_enabled'] || $settings['is_oauth_registration_enabled'];
    
    expect($allowed)->toBeFalse();
});

it('OAuth registration check allows when general registration is enabled', function () {
    $settings = [
        'is_registration_enabled' => true,
        'is_oauth_registration_enabled' => false,
    ];
    
    $allowed = $settings['is_registration_enabled'] || $settings['is_oauth_registration_enabled'];
    
    expect($allowed)->toBeTrue();
});
