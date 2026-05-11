<?php

use App\Http\Middleware\CheckForcePasswordReset;
use App\Http\Middleware\DecideWhatToDoWithUser;
use App\Models\InstanceSettings;
use App\Models\OauthSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Once;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware([DecideWhatToDoWithUser::class, CheckForcePasswordReset::class]);
    Once::flush();

    $settings = new InstanceSettings;
    $settings->id = 0;
    $settings->saveQuietly();
});

function enableGithubOauthProvider(): void
{
    OauthSetting::create([
        'provider' => 'github',
        'enabled' => true,
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'redirect_uri' => 'https://coolify.test/auth/github/callback',
    ]);
}

function mockGithubOauthUser(string $email): void
{
    $oauthUser = new SocialiteUser;
    $oauthUser->map([
        'id' => 'github-user-id',
        'name' => 'OAuth User',
        'email' => $email,
    ]);

    Socialite::shouldReceive('buildProvider')
        ->once()
        ->andReturn(new class($oauthUser)
        {
            public function __construct(private SocialiteUser $oauthUser) {}

            public function user(): SocialiteUser
            {
                return $this->oauthUser;
            }
        });
}

test('oauth users can self register when password registration is disabled', function () {
    enableGithubOauthProvider();
    instanceSettings()->update([
        'is_registration_enabled' => false,
        'is_oauth_registration_enabled' => true,
    ]);
    Once::flush();
    mockGithubOauthUser('oauth@example.com');

    $this->get('/auth/github/callback')->assertRedirect('/');

    expect(User::whereEmail('oauth@example.com')->exists())->toBeTrue();
});

test('oauth users cannot self register when both registration modes are disabled', function () {
    enableGithubOauthProvider();
    instanceSettings()->update([
        'is_registration_enabled' => false,
        'is_oauth_registration_enabled' => false,
    ]);
    Once::flush();
    mockGithubOauthUser('blocked@example.com');

    $this->get('/auth/github/callback')->assertRedirect(route('login'));

    expect(User::whereEmail('blocked@example.com')->exists())->toBeFalse();
});

test('password login can be disabled for oauth-only instances', function () {
    $user = User::factory()->create([
        'email' => 'password@example.com',
        'password' => Hash::make('password'),
    ]);
    instanceSettings()->update(['is_password_login_enabled' => false]);
    Once::flush();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    expect(auth()->check())->toBeFalse();
});
