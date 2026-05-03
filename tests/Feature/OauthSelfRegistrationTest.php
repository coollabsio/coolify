<?php

use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Models\InstanceSettings;
use App\Models\OauthSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'app.maintenance.store' => 'array',
        'cache.default' => 'array',
    ]);
    InstanceSettings::unguarded(fn () => InstanceSettings::create(['id' => 0]));
});

function enabledOauthProvider(string $provider = 'google'): void
{
    OauthSetting::create([
        'provider' => $provider,
        'enabled' => true,
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'redirect_uri' => route('auth.callback', $provider),
    ]);
}

function mockOauthUser(string $email = 'oauth@example.com', string $name = 'OAuth User'): void
{
    $provider = Mockery::mock();
    $provider->shouldReceive('setConfig')->andReturnSelf();
    $provider->shouldReceive('with')->andReturnSelf();
    $provider->shouldReceive('user')->andReturn((object) [
        'email' => $email,
        'name' => $name,
    ]);

    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
}

test('oauth self registration stays disabled by default when general registration is disabled', function () {
    instanceSettings()->update([
        'is_registration_enabled' => false,
        'is_oauth_registration_enabled' => false,
    ]);
    enabledOauthProvider();
    mockOauthUser();

    $this->get(route('auth.callback', 'google'))
        ->assertRedirect(route('login'));

    expect(User::whereEmail('oauth@example.com')->exists())->toBeFalse();
});

test('oauth users can self register when oauth registration is enabled', function () {
    instanceSettings()->update([
        'is_registration_enabled' => false,
        'is_oauth_registration_enabled' => true,
    ]);
    enabledOauthProvider();
    mockOauthUser();

    $this->get(route('auth.callback', 'google'))
        ->assertRedirect('/');

    $user = User::whereEmail('oauth@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->id)->toBe(0)
        ->and($user->oauth_provider)->toBe('google')
        ->and($user->password)->toBeNull();
    $this->assertAuthenticatedAs($user);
});

test('password login is blocked for oauth users when oauth only accounts are enabled', function () {
    instanceSettings()->update([
        'is_oauth_only_enabled' => true,
    ]);
    $user = User::factory()->create([
        'email' => 'oauth@example.com',
        'password' => Hash::make('Password1!'),
    ]);
    $user->forceFill(['oauth_provider' => 'google'])->save();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'Password1!',
    ]);

    $this->assertGuest();
});

test('password login still works for local users when oauth only accounts are enabled', function () {
    instanceSettings()->update([
        'is_oauth_only_enabled' => true,
    ]);
    $user = User::factory()->create([
        'email' => 'local@example.com',
        'password' => Hash::make('Password1!'),
    ]);

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'Password1!',
    ]);

    $this->assertAuthenticatedAs($user);
});

test('oauth only users cannot reset or change passwords', function () {
    instanceSettings()->update([
        'is_oauth_only_enabled' => true,
    ]);
    $user = User::factory()->create([
        'password' => Hash::make('Password1!'),
    ]);
    $user->forceFill(['oauth_provider' => 'google'])->save();

    (new ResetUserPassword)->reset($user, [
        'password' => 'NewPassword1!',
        'password_confirmation' => 'NewPassword1!',
    ]);
})->throws(ValidationException::class);

test('oauth only users cannot update their existing password', function () {
    instanceSettings()->update([
        'is_oauth_only_enabled' => true,
    ]);
    $user = User::factory()->create([
        'password' => Hash::make('Password1!'),
    ]);
    $user->forceFill(['oauth_provider' => 'google'])->save();

    (new UpdateUserPassword)->update($user, [
        'current_password' => 'Password1!',
        'password' => 'NewPassword1!',
        'password_confirmation' => 'NewPassword1!',
    ]);
})->throws(ValidationException::class);

test('oauth only users cannot use password based invitation links', function () {
    instanceSettings()->update([
        'is_oauth_only_enabled' => true,
    ]);
    $user = User::factory()->create([
        'email' => 'oauth-link@example.com',
        'password' => Hash::make('Password1!'),
    ]);
    $user->forceFill(['oauth_provider' => 'google'])->save();
    $token = Crypt::encryptString("{$user->email}@@@Password1!");

    $this->get(route('auth.link', ['token' => $token]))
        ->assertRedirect(route('login'))
        ->assertSessionHas('error');

    $this->assertGuest();
});

test('oauth only users skip local password confirmation checks', function () {
    instanceSettings()->update([
        'is_oauth_only_enabled' => true,
    ]);
    $user = User::factory()->create([
        'password' => Hash::make('Password1!'),
    ]);
    $user->forceFill(['oauth_provider' => 'google'])->save();

    $this->actingAs($user);

    expect(shouldSkipPasswordConfirmation())->toBeTrue();
});

test('oauth provider cannot be mass assigned onto users', function () {
    $user = new User;

    expect($user->isFillable('oauth_provider'))->toBeFalse();
});
