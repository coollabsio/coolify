<?php

use App\Models\InstanceSettings;
use App\Models\OauthSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use SocialiteProviders\Manager\Config;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::create([
        'id' => 0,
        'is_registration_enabled' => false,
    ]);

    OauthSetting::create([
        'provider' => 'google',
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'redirect_uri' => 'https://coolify.example.com/auth/google/callback',
        'tenant' => 'example.com',
    ]);
});

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

it('rejects oauth logins when the provider does not return an email address', function (?string $providerEmail) {
    config()->set('app.maintenance.driver', 'file');
    InstanceSettings::firstOrCreate([
        'id' => 0,
    ], [
        'is_registration_enabled' => false,
    ])->update([
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

it('logs in an existing user through the generic oidc provider', function () {
    config()->set('app.maintenance.driver', 'file');

    $user = User::factory()->create([
        'email' => 'oidc-user@example.com',
    ]);

    OauthSetting::create([
        'provider' => 'oidc',
        'client_id' => 'oidc-client-id',
        'client_secret' => 'oidc-client-secret',
        'redirect_uri' => 'https://coolify.example.com/auth/oidc/callback',
        'base_url' => 'https://idp.example.com/realms/coolify/',
        'scopes' => 'groups, roles',
    ]);

    $provider = Mockery::mock();
    $provider->shouldReceive('setConfig')->once()->with(Mockery::on(function ($config) {
        $values = $config instanceof Config ? $config->get() : [];

        return $values['client_id'] === 'oidc-client-id'
            && $values['client_secret'] === 'oidc-client-secret'
            && $values['redirect'] === 'https://coolify.example.com/auth/oidc/callback'
            && $values['base_url'] === 'https://idp.example.com/realms/coolify';
    }))->andReturnSelf();
    $provider->shouldReceive('scopes')->once()->with(['groups', 'roles'])->andReturnSelf();
    $provider->shouldReceive('user')->once()->andReturn((object) [
        'email' => 'OIDC-User@example.com',
        'name' => 'OIDC User',
        'id' => 'oidc-user-id',
    ]);

    Socialite::shouldReceive('driver')->once()->with('oidc')->andReturn($provider);

    $response = $this->get(route('auth.callback', 'oidc'));

    $response->assertRedirect('/');
    $this->assertAuthenticatedAs($user);
    expect(User::count())->toBe(1);
});

it('requires a base url before oidc can be enabled', function () {
    $oauthSetting = new OauthSetting([
        'provider' => 'oidc',
        'client_id' => 'oidc-client-id',
        'client_secret' => 'oidc-client-secret',
    ]);

    expect($oauthSetting->couldBeEnabled())->toBeFalse();

    $oauthSetting->base_url = 'https://idp.example.com/realms/coolify';

    expect($oauthSetting->couldBeEnabled())->toBeTrue();
});
