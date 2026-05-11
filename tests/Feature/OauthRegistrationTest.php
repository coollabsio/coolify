<?php

use App\Actions\Fortify\ResetUserPassword;
use App\Models\InstanceSettings;
use App\Models\OauthSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::create([
        'id' => 0,
        'is_registration_enabled' => false,
        'is_oauth_registration_enabled' => false,
    ]);

    OauthSetting::create([
        'provider' => 'github',
        'enabled' => true,
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
    ]);
});

function fakeGithubOauthUser(string $email = 'oauth@example.com'): void
{
    $provider = Mockery::mock();
    $provider->shouldReceive('user')->once()->andReturn((new SocialiteUser)->map([
        'id' => 'github-123',
        'name' => 'OAuth User',
        'email' => $email,
    ]));

    Socialite::shouldReceive('buildProvider')->once()->andReturn($provider);
}

it('blocks oauth self-registration when regular and oauth registration are disabled', function () {
    fakeGithubOauthUser();

    $this->get(route('auth.callback', 'github'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
    $this->assertDatabaseMissing('users', [
        'email' => 'oauth@example.com',
    ]);
});

it('allows oauth self-registration when regular registration is disabled but oauth registration is enabled', function () {
    instanceSettings()->update([
        'is_oauth_registration_enabled' => true,
    ]);

    fakeGithubOauthUser();

    $this->get(route('auth.callback', 'github'))
        ->assertRedirect('/');

    $this->assertAuthenticated();
    $this->assertDatabaseHas('users', [
        'name' => 'OAuth User',
        'email' => 'oauth@example.com',
        'password' => null,
        'oauth_provider' => 'github',
    ]);
});

it('does not authenticate oauth-only users with password credentials', function () {
    User::factory()->create([
        'email' => 'oauth@example.com',
        'password' => null,
        'oauth_provider' => 'github',
    ]);

    $this->post('/login', [
        'email' => 'oauth@example.com',
        'password' => 'Password1!',
    ])->assertSessionHasErrors();

    $this->assertGuest();
});

it('prevents oauth-only users from setting a password through password reset', function () {
    $user = User::factory()->create([
        'password' => null,
        'oauth_provider' => 'github',
    ]);

    app(ResetUserPassword::class)->reset($user, [
        'password' => 'Password1!',
        'password_confirmation' => 'Password1!',
    ]);
})->throws(ValidationException::class);
