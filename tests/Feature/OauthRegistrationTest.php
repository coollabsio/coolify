<?php

use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Models\InstanceSettings;
use App\Models\OauthSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GithubProvider;
use Laravel\Socialite\Two\User as SocialiteUser;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::create([
        'id' => 0,
        'is_registration_enabled' => false,
    ]);
});

function fakeGithubOauthUser(string $email = 'oauth@example.com'): void
{
    $provider = Mockery::mock();
    $provider->shouldReceive('user')
        ->once()
        ->andReturn((new SocialiteUser)->map([
            'id' => 'github-123',
            'name' => 'OAuth User',
            'email' => $email,
        ]));

    Socialite::shouldReceive('buildProvider')
        ->once()
        ->with(GithubProvider::class, Mockery::type('array'))
        ->andReturn($provider);
}

function createGithubOauthSetting(array $attributes = []): OauthSetting
{
    return OauthSetting::create(array_merge([
        'provider' => 'github',
        'enabled' => true,
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'redirect_uri' => 'https://coolify.test/auth/github/callback',
    ], $attributes));
}

it('keeps oauth self registration disabled by default when registration is disabled', function () {
    createGithubOauthSetting();
    fakeGithubOauthUser();

    $this->get(route('auth.callback', 'github'))
        ->assertRedirect(route('login'));

    expect(User::whereEmail('oauth@example.com')->exists())->toBeFalse();
});

it('allows enabled oauth providers to self register when registration is disabled', function () {
    createGithubOauthSetting(['is_registration_enabled' => true]);
    fakeGithubOauthUser();

    $this->get(route('auth.callback', 'github'))
        ->assertRedirect('/');

    $user = User::whereEmail('oauth@example.com')->firstOrFail();

    expect($user->oauth_provider)->toBe('github');
    expect($user->password)->toBeNull();
    $this->assertAuthenticatedAs($user);
});

it('blocks password authentication and password creation for oauth-only users', function () {
    createGithubOauthSetting([
        'is_registration_enabled' => true,
        'is_password_login_disabled' => true,
    ]);

    $user = User::factory()->create([
        'email' => 'oauth@example.com',
        'password' => bcrypt('password'),
        'oauth_provider' => 'github',
    ]);

    $this->post('/login', [
        'email' => 'oauth@example.com',
        'password' => 'password',
    ])->assertRedirect();
    $this->assertGuest();

    app(ResetUserPassword::class)->reset($user, [
        'password' => 'NewPassword1!',
        'password_confirmation' => 'NewPassword1!',
    ]);
})->throws(Symfony\Component\HttpKernel\Exception\HttpException::class);

it('blocks password updates for oauth-only users', function () {
    createGithubOauthSetting([
        'is_registration_enabled' => true,
        'is_password_login_disabled' => true,
    ]);

    $user = User::factory()->create([
        'email' => 'oauth@example.com',
        'password' => bcrypt('password'),
        'oauth_provider' => 'github',
    ]);

    app(UpdateUserPassword::class)->update($user, [
        'current_password' => 'password',
        'password' => 'NewPassword1!',
        'password_confirmation' => 'NewPassword1!',
    ]);
})->throws(Symfony\Component\HttpKernel\Exception\HttpException::class);
