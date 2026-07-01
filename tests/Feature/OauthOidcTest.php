<?php

use App\Models\InstanceSettings;
use App\Models\OauthSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::create([
        'id' => 0,
        'is_registration_enabled' => false,
    ]);

    OauthSetting::create([
        'provider' => 'oidc',
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'redirect_uri' => 'https://coolify.example.com/auth/oidc/callback',
        'base_url' => 'https://sso.example.com',
    ]);
});

it('redirects to the oidc provider login screen', function () {
    $provider = Mockery::mock();
    $provider->shouldReceive('setConfig')->once()->andReturnSelf();
    $provider->shouldReceive('redirect')->once()->andReturn(redirect('https://sso.example.com/oauth/authorize'));

    Socialite::shouldReceive('driver')->once()->with('oidc')->andReturn($provider);

    $response = $this->get(route('auth.redirect', 'oidc'));

    $response->assertRedirect('https://sso.example.com/oauth/authorize');
});

it('logs in an existing user when the oidc provider returns their email', function () {
    config()->set('app.maintenance.driver', 'file');

    $user = User::factory()->create([
        'email' => 'username@example.edu',
    ]);

    $provider = Mockery::mock();
    $provider->shouldReceive('setConfig')->once()->andReturnSelf();
    $provider->shouldReceive('user')->once()->andReturn((object) [
        'email' => 'username@example.edu',
        'name' => 'OIDC User',
        'id' => 'oidc-user-id',
    ]);

    Socialite::shouldReceive('driver')->once()->with('oidc')->andReturn($provider);

    $response = $this->get(route('auth.callback', 'oidc'));

    $response->assertRedirect('/');
    $this->assertAuthenticatedAs($user);
    expect(User::count())->toBe(1);
});

it('registers a new user and logs them in via oidc when registration is enabled', function () {
    config()->set('app.maintenance.driver', 'file');
    InstanceSettings::whereId(0)->first()->update([
        'is_registration_enabled' => true,
    ]);

    $provider = Mockery::mock();
    $provider->shouldReceive('setConfig')->once()->andReturnSelf();
    $provider->shouldReceive('user')->once()->andReturn((object) [
        'email' => 'newuser@example.edu',
        'name' => 'New OIDC User',
        'id' => 'oidc-user-id',
    ]);

    Socialite::shouldReceive('driver')->once()->with('oidc')->andReturn($provider);

    $response = $this->get(route('auth.callback', 'oidc'));

    $response->assertRedirect('/');
    $this->assertAuthenticated();
    expect(User::count())->toBe(1);
    expect(User::first()->email)->toBe('newuser@example.edu');
});
