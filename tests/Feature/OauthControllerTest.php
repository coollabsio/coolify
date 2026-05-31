<?php

use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Models\InstanceSettings;
use App\Models\OauthSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    config()->set('app.maintenance.driver', 'file');

    InstanceSettings::forceCreate([
        'id' => 0,
        'is_registration_enabled' => false,
    ]);

    OauthSetting::create([
        'provider' => 'google',
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'redirect_uri' => 'https://coolify.example.com/auth/google/callback',
        'tenant' => 'example.com',
        'enabled' => true,
    ]);
});

it('logs in an existing user when the oauth provider returns a mixed-case email', function () {
    $user = User::factory()->create([
        'email' => 'username@example.edu',
    ]);

    $provider = Mockery::mock();
    $provider->shouldReceive('setConfig')->once()->andReturnSelf();
    $provider->shouldReceive('with')->once()->with(['hd' => 'example.com'])->andReturnSelf();
    $provider->shouldReceive('user')->once()->andReturn((object) [
        'email' => 'UserName@example.edu',
        'name' => 'Example User',
        'id' => 'google-user-id',
    ]);

    Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);

    $response = $this->get(route('auth.callback', 'google'));

    $response->assertRedirect('/');
    $this->assertAuthenticatedAs($user);
    expect(User::count())->toBe(1);
});

it('creates an oauth user when provider registration is allowed and password registration is disabled', function () {
    OauthSetting::where('provider', 'google')->update([
        'allow_registration' => true,
    ]);

    $provider = Mockery::mock();
    $provider->shouldReceive('setConfig')->once()->andReturnSelf();
    $provider->shouldReceive('with')->once()->with(['hd' => 'example.com'])->andReturnSelf();
    $provider->shouldReceive('user')->once()->andReturn((object) [
        'email' => 'NewUser@example.edu',
        'name' => 'New OAuth User',
        'id' => 'google-user-id',
    ]);

    Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);

    $response = $this->get(route('auth.callback', 'google'));

    $response->assertRedirect('/');
    $user = User::whereEmail('newuser@example.edu')->first();
    expect($user)->not->toBeNull()
        ->and($user->password)->toBeNull()
        ->and($user->oauth_provider)->toBe('google');
    $this->assertAuthenticatedAs($user);
});

it('rejects password login for oauth-only users even if a password exists', function () {
    User::factory()->create([
        'email' => 'oauth-only@example.edu',
        'password' => Hash::make('password'),
        'oauth_provider' => 'google',
    ]);

    $response = $this->from('/login')->post('/login', [
        'email' => 'oauth-only@example.edu',
        'password' => 'password',
    ]);

    $response->assertRedirect('/login');
    $this->assertGuest();
});

it('rejects password reset and password update for oauth-only users', function () {
    $user = User::factory()->create([
        'password' => null,
        'oauth_provider' => 'google',
    ]);

    expect(fn () => (new ResetUserPassword)->reset($user, [
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ]))->toThrow(ValidationException::class);

    expect(fn () => (new UpdateUserPassword)->update($user, [
        'current_password' => 'password',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ]))->toThrow(ValidationException::class);

    expect($user->refresh()->password)->toBeNull();
});

it('rejects new oauth users when provider registration and password registration are disabled', function () {
    $provider = Mockery::mock();
    $provider->shouldReceive('setConfig')->once()->andReturnSelf();
    $provider->shouldReceive('with')->once()->with(['hd' => 'example.com'])->andReturnSelf();
    $provider->shouldReceive('user')->once()->andReturn((object) [
        'email' => 'NewUser@example.edu',
        'name' => 'New OAuth User',
        'id' => 'google-user-id',
    ]);

    Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);

    $response = $this->from('/login')->get(route('auth.callback', 'google'));

    $response->assertRedirect('/login');
    expect(User::whereEmail('newuser@example.edu')->exists())->toBeFalse();
});

it('rejects oauth redirects when the provider is disabled', function () {
    OauthSetting::where('provider', 'google')->update([
        'enabled' => false,
    ]);

    $response = $this->get(route('auth.redirect', 'google'));

    $response->assertForbidden();
});

it('rejects oauth logins when the provider does not return an email address', function (?string $providerEmail) {
    InstanceSettings::query()->findOrFail(0)->update([
        'is_registration_enabled' => true,
    ]);

    $provider = Mockery::mock();
    $provider->shouldReceive('setConfig')->once()->andReturnSelf();
    $provider->shouldReceive('with')->once()->with(['hd' => 'example.com'])->andReturnSelf();
    $provider->shouldReceive('user')->once()->andReturn((object) [
        'email' => $providerEmail,
        'name' => 'Example User',
        'id' => 'google-user-id',
    ]);

    Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);

    $response = $this->from('/login')->get(route('auth.callback', 'google'));

    $response->assertRedirect('/login');
    expect(User::count())->toBe(0);
})->with([
    'null email' => [null],
    'blank email' => ['   '],
]);
