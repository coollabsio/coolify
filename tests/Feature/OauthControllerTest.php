<?php

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Models\InstanceSettings;
use App\Models\OauthSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate([
        'id' => 0,
        'is_registration_enabled' => false,
        'is_oauth_registration_enabled' => false,
        'is_password_authentication_disabled' => false,
    ]);

    OauthSetting::create([
        'provider' => 'google',
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'redirect_uri' => 'https://coolify.example.com/auth/google/callback',
        'tenant' => 'example.com',
    ]);
});

function mockGoogleOauthUser(?string $email = 'username@example.edu'): void
{
    $provider = Mockery::mock();
    $provider->shouldReceive('setConfig')->once()->andReturnSelf();
    $provider->shouldReceive('with')->once()->with(['hd' => 'example.com'])->andReturnSelf();
    $provider->shouldReceive('user')->once()->andReturn((object) [
        'email' => $email,
        'name' => 'Example User',
        'id' => 'google-user-id',
    ]);

    Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);
}

it('logs in an existing user when the oauth provider returns a mixed-case email', function () {
    config()->set('app.maintenance.driver', 'file');

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

it('allows oauth self-registration when password registration is disabled', function () {
    config()->set('app.maintenance.driver', 'file');
    InstanceSettings::findOrFail(0)->update([
        'is_registration_enabled' => false,
        'is_oauth_registration_enabled' => true,
    ]);
    mockGoogleOauthUser('NewUser@example.edu');

    $response = $this->get(route('auth.callback', 'google'));

    $response->assertRedirect('/');
    $user = User::whereEmail('newuser@example.edu')->first();
    expect($user)->not->toBeNull()
        ->and($user->password)->toBeNull();
    $this->assertAuthenticatedAs($user);
});

it('rejects oauth self-registration when both registration modes are disabled', function () {
    config()->set('app.maintenance.driver', 'file');
    mockGoogleOauthUser('newuser@example.edu');

    $response = $this->from('/login')->get(route('auth.callback', 'google'));

    $response->assertRedirect('/login');
    expect(User::count())->toBe(0);
});

it('blocks password login for non-root users when password authentication is disabled', function () {
    config()->set('app.maintenance.driver', 'file');
    InstanceSettings::findOrFail(0)->update([
        'is_password_authentication_disabled' => true,
    ]);
    User::factory()->create([
        'email' => 'user@example.edu',
        'password' => Hash::make('Password1!'),
    ]);

    $response = $this->post('/login', [
        'email' => 'user@example.edu',
        'password' => 'Password1!',
    ]);

    $response->assertRedirect('/');
    $this->assertGuest();
});

it('keeps root password login available when password authentication is disabled', function () {
    config()->set('app.maintenance.driver', 'file');
    InstanceSettings::findOrFail(0)->update([
        'is_password_authentication_disabled' => true,
    ]);
    $root = User::factory()->create([
        'id' => 0,
        'email' => 'root@example.edu',
        'password' => Hash::make('Password1!'),
    ]);

    $response = $this->post('/login', [
        'email' => 'root@example.edu',
        'password' => 'Password1!',
    ]);

    $response->assertRedirect('/');
    $this->assertAuthenticatedAs($root);
});

it('blocks new password users when password authentication is disabled', function () {
    InstanceSettings::findOrFail(0)->update([
        'is_registration_enabled' => true,
        'is_password_authentication_disabled' => true,
    ]);
    User::factory()->create();

    expect(fn () => (new CreateNewUser)->create([
        'name' => 'Password User',
        'email' => 'password-user@example.edu',
        'password' => 'Password1!',
        'password_confirmation' => 'Password1!',
    ]))->toThrow(HttpException::class);
});

it('blocks password creation for non-root users when password authentication is disabled', function () {
    InstanceSettings::findOrFail(0)->update([
        'is_password_authentication_disabled' => true,
    ]);
    $user = User::factory()->create([
        'password' => null,
    ]);

    expect(fn () => (new ResetUserPassword)->reset($user, [
        'password' => 'Password1!',
        'password_confirmation' => 'Password1!',
    ]))->toThrow(HttpException::class);

    expect(fn () => (new UpdateUserPassword)->update($user, [
        'current_password' => 'Password1!',
        'password' => 'NewPassword1!',
        'password_confirmation' => 'NewPassword1!',
    ]))->toThrow(HttpException::class);
});

it('rejects oauth logins when the provider does not return an email address', function (?string $providerEmail) {
    config()->set('app.maintenance.driver', 'file');
    InstanceSettings::firstOrCreate([
        'id' => 0,
    ], [
        'is_registration_enabled' => false,
    ])->update([
        'is_registration_enabled' => true,
    ]);
    mockGoogleOauthUser($providerEmail);

    $response = $this->from('/login')->get(route('auth.callback', 'google'));

    $response->assertRedirect('/login');
    expect(User::count())->toBe(0);
})->with([
    'null email' => [null],
    'blank email' => ['   '],
]);
