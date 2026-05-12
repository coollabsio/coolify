<?php

use App\Actions\Fortify\ResetUserPassword;
use App\Models\InstanceSettings;
use App\Models\OauthSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;

uses(RefreshDatabase::class);

function createGithubOauthSetting(array $overrides = []): OauthSetting
{
    return OauthSetting::create(array_merge([
        'provider' => 'github',
        'enabled' => true,
        'allow_registration' => false,
        'disable_password_login' => false,
    ], $overrides));
}

function mockGithubOauthUser(string $email = 'oauth@example.com', string $name = 'OAuth User'): void
{
    $provider = Mockery::mock();
    $provider->shouldReceive('user')->once()->andReturn((object) [
        'email' => $email,
        'name' => $name,
    ]);

    Socialite::shouldReceive('buildProvider')->once()->andReturn($provider);
}

beforeEach(function () {
    InstanceSettings::create([
        'id' => 0,
        'is_registration_enabled' => false,
    ]);
});

it('allows oauth registration when provider registration is enabled and password registration is disabled', function () {
    createGithubOauthSetting([
        'allow_registration' => true,
        'disable_password_login' => true,
    ]);
    mockGithubOauthUser('new-oauth@example.com', 'New OAuth User');

    $response = $this->get(route('auth.callback', 'github'));

    $response->assertRedirect('/');
    $this->assertAuthenticated();

    $user = User::whereEmail('new-oauth@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->oauth_provider)->toBe('github')
        ->and($user->is_password_login_enabled)->toBeFalse();
});

it('blocks oauth registration when both provider and password registration are disabled', function () {
    createGithubOauthSetting(['allow_registration' => false]);
    mockGithubOauthUser('blocked-oauth@example.com', 'Blocked OAuth User');

    $response = $this->get(route('auth.callback', 'github'));

    $response->assertRedirect(route('login'));
    $this->assertGuest();
    expect(User::whereEmail('blocked-oauth@example.com')->exists())->toBeFalse();
});

it('keeps existing users able to sign in with oauth when provider registration is disabled', function () {
    $user = User::factory()->create([
        'email' => 'existing-oauth@example.com',
    ]);
    createGithubOauthSetting(['allow_registration' => false]);
    mockGithubOauthUser('existing-oauth@example.com', 'Existing OAuth User');

    $response = $this->get(route('auth.callback', 'github'));

    $response->assertRedirect('/');
    $this->assertAuthenticatedAs($user);
});

it('does not allow password login for oauth only users', function () {
    $user = User::factory()->create([
        'email' => 'oauth-only@example.com',
        'password' => Hash::make('Password1!'),
    ]);
    $user->forceFill([
        'oauth_provider' => 'github',
        'is_password_login_enabled' => false,
    ])->save();

    $response = $this->post('/login', [
        'email' => 'oauth-only@example.com',
        'password' => 'Password1!',
    ]);

    $response->assertRedirect();
    $this->assertGuest();
});

it('does not allow oauth only users to create a password by resetting it', function () {
    $user = User::factory()->create([
        'oauth_provider' => 'github',
        'is_password_login_enabled' => false,
    ]);

    expect(fn () => (new ResetUserPassword)->reset($user, [
        'password' => 'Password1!',
        'password_confirmation' => 'Password1!',
    ]))->toThrow(ValidationException::class);
});
