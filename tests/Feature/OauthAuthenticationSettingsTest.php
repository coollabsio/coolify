<?php

use App\Models\InstanceSettings;
use App\Models\OauthSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;

uses(RefreshDatabase::class);

function createInstanceSettingsForOauthAuth(array $overrides = []): InstanceSettings
{
    return InstanceSettings::create(array_merge([
        'id' => 0,
        'is_registration_enabled' => false,
        'is_oauth_registration_enabled' => false,
        'is_password_login_enabled' => true,
    ], $overrides));
}

function fakeGithubOauthCallback(string $email = 'oauth@example.com', string $name = 'OAuth User'): void
{
    OauthSetting::create([
        'provider' => 'github',
        'enabled' => true,
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
    ]);

    $provider = Mockery::mock();
    $provider->shouldReceive('user')->once()->andReturn((object) [
        'email' => $email,
        'name' => $name,
    ]);

    Socialite::shouldReceive('buildProvider')->once()->andReturn($provider);
}

it('allows oauth self registration when password registration is disabled but oauth registration is enabled', function () {
    createInstanceSettingsForOauthAuth([
        'is_oauth_registration_enabled' => true,
    ]);
    fakeGithubOauthCallback();

    $this->get('/auth/github/callback')->assertRedirect('/');

    $user = User::whereEmail('oauth@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->id)->toBe(0)
        ->and($user->hasPassword())->toBeFalse()
        ->and(session('currentTeam')->id)->toBe(0);
});

it('rejects new oauth users when both password and oauth registration are disabled', function () {
    createInstanceSettingsForOauthAuth();
    fakeGithubOauthCallback();

    $this->get('/auth/github/callback')
        ->assertRedirect('/login')
        ->assertSessionHasErrors();

    expect(User::whereEmail('oauth@example.com')->exists())->toBeFalse();
});

it('blocks password login when password login is disabled', function () {
    createInstanceSettingsForOauthAuth([
        'is_password_login_enabled' => false,
    ]);

    User::create([
        'name' => 'Password User',
        'email' => 'password@example.com',
        'password' => Hash::make('password'),
    ]);

    $this->post('/login', [
        'email' => 'password@example.com',
        'password' => 'password',
    ])->assertRedirect('/');

    $this->assertGuest();
});

it('hides password login and registration controls when password login is disabled', function () {
    createInstanceSettingsForOauthAuth([
        'is_registration_enabled' => true,
        'is_password_login_enabled' => false,
    ]);

    User::create([
        'name' => 'Existing User',
        'email' => 'existing@example.com',
        'password' => Hash::make('password'),
    ]);

    $this->get('/login')
        ->assertSuccessful()
        ->assertDontSee('name="email"', false)
        ->assertDontSee('name="password"', false)
        ->assertDontSee(__('auth.register_now'));
});
