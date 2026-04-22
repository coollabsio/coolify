<?php

use App\Actions\Fortify\ResetUserPassword;
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
    InstanceSettings::unguarded(function () {
        InstanceSettings::query()->create([
            'id' => 0,
            'is_registration_enabled' => false,
            'is_oauth_registration_enabled' => false,
            'is_oauth_password_creation_disabled' => false,
        ]);
    });

    OauthSetting::query()->create([
        'provider' => 'github',
        'enabled' => true,
        'client_id' => 'github-client-id',
        'client_secret' => 'github-client-secret',
        'redirect_uri' => 'http://localhost/auth/github/callback',
    ]);

    Once::flush();
});

function mockGithubOauthUser(string $email = 'oauth@example.com', string $name = 'OAuth User'): void
{
    $provider = new class($email, $name)
    {
        public function __construct(
            private string $email,
            private string $name,
        ) {}

        public function user(): object
        {
            return (object) [
                'email' => $this->email,
                'name' => $this->name,
            ];
        }
    };

    Socialite::shouldReceive('buildProvider')->once()->andReturn($provider);
}

it('keeps oauth registration disabled by default when normal registration is disabled', function () {
    mockGithubOauthUser();

    $this->get(route('auth.callback', 'github'))
        ->assertRedirect(route('login'));

    expect(User::whereEmail('oauth@example.com')->exists())->toBeFalse();
});

it('allows oauth registration when enabled even if normal registration is disabled', function () {
    InstanceSettings::findOrFail(0)->update(['is_oauth_registration_enabled' => true]);
    Once::flush();

    mockGithubOauthUser();

    $this->get(route('auth.callback', 'github'))
        ->assertRedirect('/');

    $user = User::whereEmail('oauth@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->hasPassword())->toBeFalse()
        ->and(auth()->id())->toBe($user->id);
});

it('blocks password creation for passwordless oauth users when oauth-only accounts are enabled', function () {
    InstanceSettings::findOrFail(0)->update(['is_oauth_password_creation_disabled' => true]);
    Once::flush();

    $user = User::factory()->create(['password' => null]);

    expect(fn () => (new ResetUserPassword)->reset($user, [
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ]))->toThrow(ValidationException::class);

    expect($user->refresh()->password)->toBeNull();
});

it('still allows password resets for users that already have a password', function () {
    InstanceSettings::findOrFail(0)->update(['is_oauth_password_creation_disabled' => true]);
    Once::flush();

    $user = User::factory()->create(['password' => Hash::make('old-password')]);

    (new ResetUserPassword)->reset($user, [
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ]);

    expect(Hash::check('Password123!', $user->refresh()->password))->toBeTrue();
});
