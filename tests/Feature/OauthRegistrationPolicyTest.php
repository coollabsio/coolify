<?php

use App\Actions\Fortify\ResetUserPassword;
use App\Http\Middleware\PreventRequestsDuringMaintenance;
use App\Models\InstanceSettings;
use App\Models\OauthSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Once;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

uses(RefreshDatabase::class);

beforeEach(function () {
    Once::flush();
    $this->withoutMiddleware(PreventRequestsDuringMaintenance::class);
});

function createGoogleOauthSetting(): void
{
    OauthSetting::create([
        'provider' => 'google',
        'enabled' => true,
        'client_id' => 'google-client-id',
        'client_secret' => 'google-client-secret',
        'redirect_uri' => route('auth.callback', 'google'),
    ]);
}

function fakeGoogleOauthUser(string $email = 'oauth@example.com', string $name = 'OAuth User'): void
{
    $socialiteUser = new SocialiteUser;
    $socialiteUser->map([
        'email' => $email,
        'name' => $name,
    ]);

    $provider = Mockery::mock();
    $provider->shouldReceive('setConfig')->once()->andReturnSelf();
    $provider->shouldReceive('with')->once()->with(['hd' => null])->andReturnSelf();
    $provider->shouldReceive('user')->once()->andReturn($socialiteUser);

    Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);
}

it('allows oauth callback to create new users when regular registration is disabled', function () {
    InstanceSettings::unguarded(function () {
        InstanceSettings::create([
            'id' => 0,
            'is_registration_enabled' => false,
        ]);
    });
    User::factory()->create();
    createGoogleOauthSetting();
    fakeGoogleOauthUser();

    $response = $this->get(route('auth.callback', 'google'));

    $response->assertRedirect('/');
    $this->assertAuthenticated();

    $user = User::whereEmail('oauth@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('OAuth User')
        ->and($user->is_oauth_user)->toBeTrue()
        ->and($user->hasPassword())->toBeFalse();
});

it('prevents oauth users from creating a password when password auth is disabled for oauth users', function () {
    InstanceSettings::unguarded(function () {
        InstanceSettings::create([
            'id' => 0,
            'disable_password_auth_for_oauth_users' => true,
        ]);
    });
    $user = User::factory()->create([
        'is_oauth_user' => true,
        'password' => null,
    ]);

    expect(fn () => app(ResetUserPassword::class)->reset($user, [
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ]))->toThrow(ValidationException::class);

    expect($user->fresh()->password)->toBeNull();
});

it('allows oauth users to create a password when password auth is enabled for oauth users', function () {
    InstanceSettings::unguarded(function () {
        InstanceSettings::create([
            'id' => 0,
            'disable_password_auth_for_oauth_users' => false,
        ]);
    });
    $user = User::factory()->create([
        'is_oauth_user' => true,
        'password' => null,
    ]);

    app(ResetUserPassword::class)->reset($user, [
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ]);

    expect($user->fresh()->hasPassword())->toBeTrue();
});

it('rejects password login for oauth users when password auth is disabled for oauth users', function () {
    InstanceSettings::unguarded(function () {
        InstanceSettings::create([
            'id' => 0,
            'disable_password_auth_for_oauth_users' => true,
        ]);
    });
    $user = User::factory()->create([
        'email' => 'oauth-with-password@example.com',
        'is_oauth_user' => true,
        'password' => Hash::make('Password123!'),
    ]);

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'Password123!',
    ]);

    $response->assertSessionHasErrors();
    $this->assertGuest();
});
