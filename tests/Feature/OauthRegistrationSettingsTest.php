<?php

use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Models\InstanceSettings;
use App\Models\OauthSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Once;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;

uses(RefreshDatabase::class);

beforeEach(function () {
    Once::flush();
});

function createOauthRegistrationTestSettings(array $overrides = []): InstanceSettings
{
    return InstanceSettings::create(array_merge([
        'id' => 0,
        'is_registration_enabled' => false,
        'is_oauth_registration_enabled' => false,
        'is_oauth_password_login_enabled' => true,
    ], $overrides));
}

function fakeGithubOauthRegistrationTestUser(string $email = 'oauth@example.com'): void
{
    OauthSetting::create([
        'provider' => 'github',
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'redirect_uri' => route('auth.callback', 'github'),
        'enabled' => true,
    ]);

    $provider = Mockery::mock();
    $provider->shouldReceive('user')->once()->andReturn((object) [
        'name' => 'OAuth User',
        'email' => $email,
    ]);

    Socialite::shouldReceive('buildProvider')->once()->andReturn($provider);
}

it('allows oauth self registration when regular registration is disabled and oauth registration is enabled', function () {
    createOauthRegistrationTestSettings([
        'is_oauth_registration_enabled' => true,
    ]);
    fakeGithubOauthRegistrationTestUser();

    $response = $this->get(route('auth.callback', 'github'));

    $response->assertRedirect('/');

    $user = User::where('email', 'oauth@example.com')->firstOrFail();
    expect($user->oauth_provider)->toBe('github');
    $this->assertAuthenticatedAs($user);
});

it('blocks oauth self registration when both registration modes are disabled', function () {
    createOauthRegistrationTestSettings();
    fakeGithubOauthRegistrationTestUser();

    $response = $this->get(route('auth.callback', 'github'));

    $response->assertRedirect(route('login'));
    expect(User::where('email', 'oauth@example.com')->exists())->toBeFalse();
});

it('marks existing users as oauth users after a successful oauth login', function () {
    createOauthRegistrationTestSettings();
    fakeGithubOauthRegistrationTestUser();

    $user = User::factory()->create([
        'email' => 'oauth@example.com',
        'password' => Hash::make('password'),
        'oauth_provider' => null,
    ]);

    $response = $this->get(route('auth.callback', 'github'));

    $response->assertRedirect('/');
    expect($user->refresh()->oauth_provider)->toBe('github');
});

it('blocks password resets and password updates for oauth users when password auth is disabled', function () {
    createOauthRegistrationTestSettings([
        'is_oauth_password_login_enabled' => false,
    ]);

    $user = User::factory()->create([
        'password' => Hash::make('password'),
        'oauth_provider' => 'github',
    ]);

    expect($user->canUsePasswordAuthentication())->toBeFalse();

    expect(fn () => (new ResetUserPassword)->reset($user, [
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ]))->toThrow(ValidationException::class);

    expect(fn () => (new UpdateUserPassword)->update($user, []))
        ->toThrow(ValidationException::class);
});

it('keeps password authentication available for regular users when oauth password auth is disabled', function () {
    createOauthRegistrationTestSettings([
        'is_oauth_password_login_enabled' => false,
    ]);

    $user = User::factory()->create([
        'password' => Hash::make('password'),
    ]);

    expect($user->isOauthUser())->toBeFalse();
    expect($user->canUsePasswordAuthentication())->toBeTrue();
});

it('applies oauth password restrictions to legacy passwordless users', function () {
    createOauthRegistrationTestSettings([
        'is_oauth_password_login_enabled' => false,
    ]);

    $user = User::factory()->create([
        'password' => null,
        'oauth_provider' => null,
    ]);

    expect($user->isOauthUser())->toBeFalse();
    expect($user->hasPassword())->toBeFalse();
    expect($user->canUsePasswordAuthentication())->toBeFalse();
});
