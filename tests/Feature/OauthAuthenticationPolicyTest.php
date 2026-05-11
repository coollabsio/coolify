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

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware([DecideWhatToDoWithUser::class, CheckForcePasswordReset::class]);
    Once::flush();

    InstanceSettings::forceCreate([
        'id' => 0,
        'is_registration_enabled' => false,
        'is_oauth_registration_enabled' => false,
        'is_password_login_enabled_for_oauth_users' => true,
    ]);
});

function createOauthAuthenticationPolicySetting(): void
{
    OauthSetting::create([
        'provider' => 'github',
        'enabled' => true,
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'redirect_uri' => route('auth.callback', 'github'),
    ]);
}

function fakeOauthAuthenticationPolicyUser(
    string $email = 'oauth@example.com',
    string $id = 'oauth-user-id',
    string $name = 'OAuth User'
): void
{
    Socialite::shouldReceive('buildProvider')->andReturn(new class($email, $id, $name)
    {
        public function __construct(
            private string $email,
            private string $id,
            private string $name,
        ) {}

        public function user(): object
        {
            return (object) [
                'id' => $this->id,
                'name' => $this->name,
                'email' => $this->email,
            ];
        }
    });
}

test('oauth callback can create a user when oauth registration is enabled and normal registration is disabled', function () {
    createOauthAuthenticationPolicySetting();
    fakeOauthAuthenticationPolicyUser();

    instanceSettings()->forceFill([
        'is_registration_enabled' => false,
        'is_oauth_registration_enabled' => true,
    ])->save();

    $this->get(route('auth.callback', 'github'))->assertRedirect('/');

    $user = User::whereEmail('oauth@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->oauth_provider)->toBe('github')
        ->and($user->oauth_id)->toBe('oauth-user-id')
        ->and($user->hasPassword())->toBeFalse();
});

test('oauth callback does not create a user when all registration paths are disabled', function () {
    createOauthAuthenticationPolicySetting();
    fakeOauthAuthenticationPolicyUser();

    $this->get(route('auth.callback', 'github'))->assertRedirect(route('login'));

    expect(User::whereEmail('oauth@example.com')->exists())->toBeFalse();
});

test('oauth callback matches an existing linked user by provider id before email', function () {
    createOauthAuthenticationPolicySetting();
    fakeOauthAuthenticationPolicyUser(email: 'changed@example.com');

    $user = User::factory()->create([
        'email' => 'original@example.com',
        'oauth_provider' => 'github',
        'oauth_id' => 'oauth-user-id',
    ]);

    $this->get(route('auth.callback', 'github'))->assertRedirect('/');

    $this->assertAuthenticatedAs($user->fresh());

    expect(User::count())->toBe(1);
});

test('password login can be disabled for users linked to an oauth provider', function () {
    instanceSettings()->forceFill([
        'is_password_login_enabled_for_oauth_users' => false,
    ])->save();

    $user = User::factory()->create([
        'email' => 'oauth-password@example.com',
        'password' => Hash::make('password'),
        'oauth_provider' => 'github',
        'oauth_id' => 'oauth-user-id',
    ]);

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertGuest();
});
