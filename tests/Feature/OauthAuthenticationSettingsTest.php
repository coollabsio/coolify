<?php

use App\Actions\Fortify\ResetUserPassword;
use App\Models\InstanceSettings;
use App\Models\OauthSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;
use Mockery;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::create(['id' => 0]);
});

it('allows oauth self registration when password registration is disabled', function () {
    instanceSettings()->update([
        'is_registration_enabled' => false,
        'is_oauth_registration_enabled' => true,
    ]);

    OauthSetting::create([
        'provider' => 'github',
        'enabled' => true,
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
    ]);

    $oauthProvider = Mockery::mock();
    $oauthProvider->shouldReceive('user')->once()->andReturn((object) [
        'name' => 'OAuth User',
        'email' => 'oauth@example.com',
    ]);

    Socialite::shouldReceive('buildProvider')->once()->andReturn($oauthProvider);

    $response = $this->get('/auth/github/callback');

    $response->assertRedirect('/');
    $this->assertAuthenticated();
    expect(User::whereEmail('oauth@example.com')->exists())->toBeTrue();
});

it('blocks oauth self registration when both registration paths are disabled', function () {
    instanceSettings()->update([
        'is_registration_enabled' => false,
        'is_oauth_registration_enabled' => false,
    ]);

    OauthSetting::create([
        'provider' => 'github',
        'enabled' => true,
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
    ]);

    $oauthProvider = Mockery::mock();
    $oauthProvider->shouldReceive('user')->once()->andReturn((object) [
        'name' => 'OAuth User',
        'email' => 'oauth@example.com',
    ]);

    Socialite::shouldReceive('buildProvider')->once()->andReturn($oauthProvider);

    $response = $this->get('/auth/github/callback');

    $response->assertRedirect('/login');
    $response->assertSessionHasErrors();
    $this->assertGuest();
    expect(User::whereEmail('oauth@example.com')->exists())->toBeFalse();
});

it('renders oauth self registration messaging on the login page', function () {
    instanceSettings()->update([
        'is_registration_enabled' => false,
        'is_oauth_registration_enabled' => true,
    ]);

    OauthSetting::create([
        'provider' => 'github',
        'enabled' => true,
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
    ]);

    User::factory()->create([
        'email' => 'existing@example.com',
    ]);

    $response = $this->get('/login');

    $response->assertOk();
    $response->assertSee('Self-registration is available through the OAuth providers below.');
    $response->assertDontSee('Register Now');
});

it('prevents oauth-only accounts from creating passwords through reset flow', function () {
    instanceSettings()->update([
        'is_oauth_only_enabled' => true,
    ]);

    $user = User::factory()->create([
        'email' => 'oauth@example.com',
        'password' => null,
    ]);

    expect(fn () => app(ResetUserPassword::class)->reset($user, [
        'password' => 'Password1!@',
        'password_confirmation' => 'Password1!@',
    ]))->toThrow(ValidationException::class);

    $user->refresh();
    expect($user->password)->toBeNull();
});
