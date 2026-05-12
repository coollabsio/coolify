<?php

use App\Actions\Fortify\CreateNewUser;
use App\Http\Controllers\OauthController;
use App\Models\InstanceSettings;
use App\Models\OauthSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));
});

function setAuthSettings(bool $passwordAuthentication, bool $passwordRegistration, bool $oauthRegistration = false): void
{
    InstanceSettings::query()->update([
        'is_password_authentication_enabled' => $passwordAuthentication,
        'is_registration_enabled' => $passwordRegistration,
        'is_oauth_registration_enabled' => $oauthRegistration,
    ]);
}

it('hides password login and registration when password authentication is disabled', function () {
    $this->withoutVite();
    $this->withViewErrors([]);

    $html = view('auth.login', [
        'is_registration_enabled' => true,
        'is_password_authentication_enabled' => false,
        'enabled_oauth_providers' => collect(),
    ])->render();

    expect($html)
        ->not->toContain('name="password"')
        ->not->toContain(__('auth.register_now'));
});

it('rejects password login when password authentication is disabled', function () {
    User::factory()->create([
        'email' => 'user@example.com',
        'password' => Hash::make('password'),
    ]);
    setAuthSettings(passwordAuthentication: false, passwordRegistration: true);

    $this->post('/login', [
        'email' => 'user@example.com',
        'password' => 'password',
    ]);

    $this->assertGuest();
});

it('rejects password registration when password authentication is disabled', function () {
    User::factory()->create();
    setAuthSettings(passwordAuthentication: false, passwordRegistration: true);

    (new CreateNewUser)->create([
        'name' => 'New User',
        'email' => 'new@example.com',
        'password' => 'Password1!',
        'password_confirmation' => 'Password1!',
    ]);
})->throws(HttpException::class);

it('allows oauth self-registration when password registration is disabled', function () {
    setAuthSettings(passwordAuthentication: false, passwordRegistration: false, oauthRegistration: true);

    OauthSetting::create([
        'provider' => 'github',
        'enabled' => true,
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'redirect_uri' => route('auth.callback', 'github'),
    ]);

    $provider = Mockery::mock();
    $provider->shouldReceive('user')->once()->andReturn((object) [
        'name' => 'OAuth User',
        'email' => 'oauth@example.com',
    ]);

    Socialite::shouldReceive('buildProvider')->once()->andReturn($provider);

    $response = app(OauthController::class)->callback('github');

    expect($response->getTargetUrl())->toBe(url('/'))
        ->and(auth()->check())->toBeTrue()
        ->and(User::whereEmail('oauth@example.com')->first())
        ->password->toBeNull();
});
