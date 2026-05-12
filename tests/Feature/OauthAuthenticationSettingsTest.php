<?php

use App\Models\InstanceSettings;
use App\Models\OauthSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::create(['id' => 0]);
});

test('oauth can create users when password registration is disabled but oauth registration is enabled', function () {
    InstanceSettings::find(0)->update([
        'is_registration_enabled' => false,
        'is_oauth_registration_enabled' => true,
    ]);

    OauthSetting::create([
        'provider' => 'github',
        'enabled' => true,
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'redirect_uri' => 'https://coolify.example.com/auth/github/callback',
    ]);

    $provider = Mockery::mock();
    $provider->shouldReceive('user')->once()->andReturn((object) [
        'name' => 'OAuth User',
        'email' => 'oauth@example.com',
    ]);

    Socialite::shouldReceive('buildProvider')->once()->andReturn($provider);

    $this->get('/auth/github/callback')
        ->assertRedirect('/');

    $user = User::whereEmail('oauth@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->hasPassword())->toBeFalse();
});

test('oauth cannot create users when both registration modes are disabled', function () {
    InstanceSettings::find(0)->update([
        'is_registration_enabled' => false,
        'is_oauth_registration_enabled' => false,
    ]);

    OauthSetting::create([
        'provider' => 'github',
        'enabled' => true,
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'redirect_uri' => 'https://coolify.example.com/auth/github/callback',
    ]);

    $provider = Mockery::mock();
    $provider->shouldReceive('user')->once()->andReturn((object) [
        'name' => 'Blocked User',
        'email' => 'blocked@example.com',
    ]);

    Socialite::shouldReceive('buildProvider')->once()->andReturn($provider);

    $this->get('/auth/github/callback')
        ->assertRedirect(route('login'));

    expect(User::whereEmail('blocked@example.com')->exists())->toBeFalse();
});

test('password login is rejected when password login is disabled', function () {
    InstanceSettings::find(0)->update(['is_password_login_enabled' => false]);

    $user = User::factory()->create([
        'email' => 'password@example.com',
        'password' => Hash::make('password'),
    ]);

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertSessionHasErrors();

    $this->assertGuest();
});

test('password login form and password registration link are hidden when password login is disabled', function () {
    InstanceSettings::find(0)->update([
        'is_registration_enabled' => true,
        'is_password_login_enabled' => false,
    ]);

    User::factory()->create();

    $this->get('/login')
        ->assertOk()
        ->assertDontSee('name="password"', false)
        ->assertDontSee(__('auth.register_now'));
});
