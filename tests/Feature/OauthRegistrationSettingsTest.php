<?php

use App\Http\Controllers\OauthController;
use App\Models\InstanceSettings;
use App\Models\OauthSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;

uses(RefreshDatabase::class);

function fakeOauthProvider(string $email = 'oauth@example.com', string $name = 'OAuth User'): object
{
    return new class($email, $name)
    {
        public function __construct(private string $email, private string $name) {}

        public function user(): object
        {
            return (object) [
                'email' => $this->email,
                'name' => $this->name,
            ];
        }
    };
}

beforeEach(function () {
    config()->set('app.maintenance.driver', 'file');

    InstanceSettings::unguarded(fn () => InstanceSettings::create(['id' => 0]));

    OauthSetting::create([
        'provider' => 'github',
        'enabled' => true,
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'redirect_uri' => 'http://localhost/auth/github/callback',
    ]);
});

it('allows oauth registration when password registration is disabled but oauth registration is enabled', function () {
    $settings = instanceSettings();
    $settings->is_registration_enabled = false;
    $settings->is_oauth_registration_enabled = true;
    $settings->save();

    Socialite::shouldReceive('buildProvider')
        ->once()
        ->andReturn(fakeOauthProvider());

    app(OauthController::class)->callback('github');

    $user = User::whereEmail('oauth@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->oauth_provider)->toBe('github')
        ->and(auth()->check())->toBeTrue();
});

it('blocks oauth registration when both password and oauth registration are disabled', function () {
    $settings = instanceSettings();
    $settings->is_registration_enabled = false;
    $settings->is_oauth_registration_enabled = false;
    $settings->save();

    Socialite::shouldReceive('buildProvider')
        ->once()
        ->andReturn(fakeOauthProvider());

    app(OauthController::class)->callback('github');

    expect(User::whereEmail('oauth@example.com')->exists())->toBeFalse()
        ->and(auth()->check())->toBeFalse();
});

it('blocks password login for users linked to oauth when oauth password login is disabled', function () {
    $settings = instanceSettings();
    $settings->is_oauth_password_login_disabled = true;
    $settings->save();

    $user = User::factory()->create([
        'email' => 'oauth@example.com',
        'password' => Hash::make('password'),
        'oauth_provider' => 'github',
    ]);

    // Exercise the configured Fortify callback by sending a real login request.
    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertSessionHasErrors();

    expect(auth()->check())->toBeFalse();
});
