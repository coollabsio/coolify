<?php

use App\Actions\Fortify\ResetUserPassword;
use App\Http\Middleware\CheckForcePasswordReset;
use App\Http\Middleware\DecideWhatToDoWithUser;
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
    $this->withoutMiddleware([DecideWhatToDoWithUser::class, CheckForcePasswordReset::class]);
    Once::flush();

    InstanceSettings::unguarded(function () {
        InstanceSettings::query()->create([
            'id' => 0,
            'is_registration_enabled' => false,
        ]);
    });

    OauthSetting::query()->create([
        'provider' => 'github',
        'enabled' => true,
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'redirect_uri' => 'http://localhost/auth/github/callback',
    ]);
});

test('oauth callback self-registers a provider user when normal registration is disabled', function () {
    User::factory()->create();

    $provider = Mockery::mock();
    $provider->shouldReceive('user')->once()->andReturn((object) [
        'email' => 'oauth-user@example.com',
        'name' => 'OAuth User',
    ]);
    Socialite::shouldReceive('buildProvider')->once()->andReturn($provider);

    $this->get(route('auth.callback', 'github'))->assertRedirect('/');

    $user = User::query()->where('email', 'oauth-user@example.com')->firstOrFail();
    expect($user->oauth_provider)->toBe('github');
    expect($user->password)->toBeNull();
    expect($user->isOauthOnly())->toBeTrue();
    expect($user->canUsePasswordAuthentication())->toBeFalse();
    expect(auth()->id())->toBe($user->id);
});

test('oauth users cannot use password login even if a password exists', function () {
    $user = User::factory()->create([
        'email' => 'oauth-login@example.com',
        'oauth_provider' => 'github',
        'password' => Hash::make('Password1!'),
    ]);

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'Password1!',
    ])->assertSessionHasErrors();

    expect(auth()->guest())->toBeTrue();
});

test('oauth users cannot create a password through password reset', function () {
    $user = User::factory()->create([
        'oauth_provider' => 'github',
        'password' => null,
    ]);

    expect(fn () => (new ResetUserPassword)->reset($user, [
        'password' => 'Password1!',
        'password_confirmation' => 'Password1!',
    ]))->toThrow(ValidationException::class);

    $user->refresh();
    expect($user->password)->toBeNull();
});
