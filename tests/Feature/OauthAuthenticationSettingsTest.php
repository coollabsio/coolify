<?php

use App\Actions\Fortify\ResetUserPassword;
use App\Models\InstanceSettings;
use App\Models\OauthSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(function () {
        InstanceSettings::query()->create([
            'id' => 0,
            'is_registration_enabled' => false,
            'is_oauth_registration_enabled' => false,
            'is_oauth_password_auth_disabled' => false,
        ]);
    });

    OauthSetting::query()->create([
        'provider' => 'github',
        'enabled' => true,
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'redirect_uri' => 'http://coolify.test/auth/github/callback',
    ]);
});

it('allows oauth self registration when regular registration is disabled', function () {
    instanceSettings()->update(['is_oauth_registration_enabled' => true]);

    $provider = Mockery::mock();
    $provider->shouldReceive('user')->once()->andReturn((object) [
        'name' => 'OAuth User',
        'email' => 'oauth@example.com',
    ]);

    Socialite::shouldReceive('buildProvider')->once()->andReturn($provider);

    $this->get(route('auth.callback', 'github'))->assertRedirect('/');

    $user = User::whereEmail('oauth@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->oauth_provider)->toBe('github')
        ->and($user->password)->toBeNull();
});

it('blocks password login for oauth users when oauth-only mode is enabled', function () {
    instanceSettings()->update(['is_oauth_password_auth_disabled' => true]);

    User::factory()->create([
        'email' => 'oauth@example.com',
        'password' => Hash::make('password'),
        'oauth_provider' => 'github',
    ]);

    $this->post('/login', [
        'email' => 'oauth@example.com',
        'password' => 'password',
    ])->assertSessionHasErrors('email');
});

it('blocks password reset for oauth users when oauth-only mode is enabled', function () {
    instanceSettings()->update(['is_oauth_password_auth_disabled' => true]);

    $user = User::factory()->create([
        'oauth_provider' => 'github',
    ]);

    (new ResetUserPassword)->reset($user, [
        'password' => 'Password1!@',
        'password_confirmation' => 'Password1!@',
    ]);
})->throws(Symfony\Component\HttpKernel\Exception\HttpException::class);
