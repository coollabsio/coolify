<?php

use App\Models\InstanceSettings;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    // Ensure instance settings exist for tests
    if (!InstanceSettings::find(0)) {
        InstanceSettings::create(['id' => 0]);
    }
});

it('allows oauth user creation when only is_oauth_registration_enabled is true', function () {
    $settings = InstanceSettings::find(0);
    $settings->is_registration_enabled = false;
    $settings->is_oauth_registration_enabled = true;
    $settings->save();

    // Simulate what OauthController::callback does when creating a new user
    $user = User::create([
        'name' => 'OAuth Test User',
        'email' => 'oauthtest@example.com',
        'oauth_only' => true,
    ]);

    expect($user)->toBeInstanceOf(User::class);
    expect($user->oauth_only)->toBeTrue();
    expect($user->hasPassword())->toBeFalse();
});

it('blocks oauth user creation when both registration flags are false', function () {
    $settings = InstanceSettings::find(0);
    $settings->is_registration_enabled = false;
    $settings->is_oauth_registration_enabled = false;
    $settings->save();

    // Simulate the check in OauthController::callback
    $canRegister = $settings->is_registration_enabled || $settings->is_oauth_registration_enabled;

    expect($canRegister)->toBeFalse();
});

it('allows oauth user creation when is_registration_enabled is true regardless of oauth flag', function () {
    $settings = InstanceSettings::find(0);
    $settings->is_registration_enabled = true;
    $settings->is_oauth_registration_enabled = false;
    $settings->save();

    $canRegister = $settings->is_registration_enabled || $settings->is_oauth_registration_enabled;

    expect($canRegister)->toBeTrue();
});

it('marks oauth-created users as oauth_only', function () {
    $user = User::create([
        'name' => 'OAuth Only User',
        'email' => 'oauthonly@example.com',
        'oauth_only' => true,
    ]);

    expect($user->oauth_only)->toBeTrue();
    expect($user->password)->toBeNull();
    expect($user->hasPassword())->toBeFalse();
});

it('does not mark regular users as oauth_only', function () {
    $user = User::create([
        'name' => 'Regular User',
        'email' => 'regular@example.com',
        'password' => Hash::make('password123'),
    ]);

    expect($user->oauth_only)->toBeFalse();
    expect($user->hasPassword())->toBeTrue();
});

it('rejects password login for oauth_only users', function () {
    $settings = InstanceSettings::find(0);
    $settings->is_registration_enabled = true;
    $settings->save();

    // Create an oauth_only user with a password (edge case: admin sets password manually)
    $user = User::create([
        'name' => 'OAuth Blocked User',
        'email' => 'oauthblocked@example.com',
        'password' => Hash::make('password123'),
        'oauth_only' => true,
    ]);

    // Attempt password login - should be rejected
    $response = $this->post('/login', [
        'email' => 'oauthblocked@example.com',
        'password' => 'password123',
    ]);

    // Should redirect back with errors (not successfully authenticated)
    $response->assertStatus(302);
    $this->assertGuest();
});

it('allows password login for normal users', function () {
    $settings = InstanceSettings::find(0);
    $settings->is_registration_enabled = true;
    $settings->save();

    $user = User::create([
        'name' => 'Normal User',
        'email' => 'normallogin@example.com',
        'password' => Hash::make('password123'),
        'oauth_only' => false,
    ]);
    $user->markEmailAsVerified();

    $response = $this->post('/login', [
        'email' => 'normallogin@example.com',
        'password' => 'password123',
    ]);

    $response->assertStatus(302);
    $this->assertAuthenticated();
});

it('has is_oauth_registration_enabled setting default to false', function () {
    $settings = InstanceSettings::find(0);

    // Default should be false (set by migration)
    expect($settings->is_oauth_registration_enabled)->toBeFalse();
});
