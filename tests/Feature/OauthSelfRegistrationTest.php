<?php

use App\Models\InstanceSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Fortify;

uses(RefreshDatabase::class);

function ensureInstanceSettings(): InstanceSettings
{
    $existing = InstanceSettings::query()->find(0);
    if ($existing) {
        return $existing;
    }

    return InstanceSettings::unguarded(function () {
        return InstanceSettings::query()->create(['id' => 0]);
    });
}

beforeEach(function () {
    ensureInstanceSettings();
});

it('persists the new oauth instance settings flags', function () {
    $settings = ensureInstanceSettings();

    expect($settings->is_oauth_registration_enabled)->toBeFalse()
        ->and($settings->disable_password_login_for_oauth_users)->toBeFalse();

    $settings->is_oauth_registration_enabled = true;
    $settings->disable_password_login_for_oauth_users = true;
    $settings->save();

    $fresh = InstanceSettings::query()->find(0);
    expect($fresh->is_oauth_registration_enabled)->toBeTrue()
        ->and($fresh->disable_password_login_for_oauth_users)->toBeTrue();
});

it('persists oauth_provider on the user model', function () {
    $user = User::factory()->create([
        'email' => 'oauth@example.com',
        'oauth_provider' => 'github',
    ]);

    expect($user->fresh()->oauth_provider)->toBe('github');
});

it('blocks password login for oauth users when restriction is enabled', function () {
    $settings = ensureInstanceSettings();
    $settings->disable_password_login_for_oauth_users = true;
    $settings->save();

    User::factory()->create([
        'email' => 'oauthonly@example.com',
        'password' => Hash::make('secret-password'),
        'oauth_provider' => 'github',
    ]);

    $callback = Fortify::$authenticateUsingCallback;
    expect($callback)->not->toBeNull();

    $request = Request::create('/login', 'POST', [
        'email' => 'oauthonly@example.com',
        'password' => 'secret-password',
    ]);

    $result = $callback($request);
    expect($result)->toBeNull();
});

it('allows password login for non-oauth users when restriction is enabled', function () {
    $settings = ensureInstanceSettings();
    $settings->disable_password_login_for_oauth_users = true;
    $settings->save();

    User::factory()->create([
        'email' => 'localonly@example.com',
        'password' => Hash::make('secret-password'),
        'oauth_provider' => null,
    ]);

    $callback = Fortify::$authenticateUsingCallback;
    $request = Request::create('/login', 'POST', [
        'email' => 'localonly@example.com',
        'password' => 'secret-password',
    ]);

    $result = $callback($request);
    expect($result)->not->toBeNull()
        ->and($result->email)->toBe('localonly@example.com');
});

it('allows password login for oauth users when restriction is disabled', function () {
    $settings = ensureInstanceSettings();
    $settings->disable_password_login_for_oauth_users = false;
    $settings->save();

    User::factory()->create([
        'email' => 'mixed@example.com',
        'password' => Hash::make('secret-password'),
        'oauth_provider' => 'github',
    ]);

    $callback = Fortify::$authenticateUsingCallback;
    $request = Request::create('/login', 'POST', [
        'email' => 'mixed@example.com',
        'password' => 'secret-password',
    ]);

    $result = $callback($request);
    expect($result)->not->toBeNull();
});
