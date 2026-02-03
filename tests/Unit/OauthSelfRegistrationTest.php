<?php

/**
 * Tests for OAuth self-registration feature
 *
 * These tests verify that the OAuth registration logic works correctly
 * when the is_oauth_registration_enabled setting is used.
 */

use App\Models\InstanceSettings;
use App\Models\User;

it('has is_oauth_registration_enabled cast to boolean in InstanceSettings', function () {
    $settings = new InstanceSettings;
    $casts = $settings->getCasts();

    expect($casts)->toHaveKey('is_oauth_registration_enabled')
        ->and($casts['is_oauth_registration_enabled'])->toBe('boolean');
});

it('defaults is_oauth_registration_enabled to false', function () {
    $settings = new InstanceSettings;
    $settings->is_oauth_registration_enabled = false;

    expect($settings->is_oauth_registration_enabled)->toBeFalse()
        ->and($settings->is_oauth_registration_enabled)->toBeBool();
});

it('casts is_oauth_registration_enabled from string "1" to boolean true', function () {
    $settings = new InstanceSettings;
    $settings->is_oauth_registration_enabled = '1';

    expect($settings->is_oauth_registration_enabled)->toBeTrue()
        ->and($settings->is_oauth_registration_enabled)->toBeBool();
});

it('casts is_oauth_registration_enabled from string "0" to boolean false', function () {
    $settings = new InstanceSettings;
    $settings->is_oauth_registration_enabled = '0';

    expect($settings->is_oauth_registration_enabled)->toBeFalse()
        ->and($settings->is_oauth_registration_enabled)->toBeBool();
});

it('user can be identified as oauth user when oauth_provider is set', function () {
    $user = new User;
    $user->oauth_provider = 'github';
    $user->oauth_id = '12345';

    expect($user->isOauthUser())->toBeTrue()
        ->and($user->getOauthProvider())->toBe('github');
});

it('user is not oauth user when oauth_provider is null', function () {
    $user = new User;
    $user->oauth_provider = null;

    expect($user->isOauthUser())->toBeFalse()
        ->and($user->getOauthProvider())->toBeNull();
});

it('user is not oauth user when oauth_provider is empty string', function () {
    $user = new User;
    $user->oauth_provider = '';

    expect($user->isOauthUser())->toBeFalse();
});

it('user has no password when created via oauth', function () {
    $user = new User;
    $user->name = 'Test User';
    $user->email = 'test@example.com';
    $user->oauth_provider = 'github';
    $user->password = null;

    expect($user->hasPassword())->toBeFalse();
});

it('user has password when set', function () {
    $user = new User;
    $user->name = 'Test User';
    $user->email = 'test@example.com';
    $user->password = 'hashed_password';

    expect($user->hasPassword())->toBeTrue();
});
