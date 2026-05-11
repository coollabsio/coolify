<?php

use App\Models\InstanceSettings;
use App\Models\OauthSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Once;
use Laravel\Socialite\Facades\Socialite;

uses(RefreshDatabase::class);

beforeEach(function () {
    Once::flush();

    InstanceSettings::updateOrCreate([
        'id' => 0,
    ], [
        'is_registration_enabled' => false,
        'is_oauth_registration_enabled' => true,
        'is_oauth_only_auth_enabled' => true,
        'smtp_enabled' => true,
        'smtp_host' => 'smtp.example.com',
        'smtp_port' => 587,
        'smtp_encryption' => 'tls',
        'smtp_from_address' => 'noreply@example.com',
        'smtp_from_name' => 'Coolify',
    ]);

    OauthSetting::create([
        'provider' => 'google',
        'enabled' => true,
        'client_id' => 'google-client-id',
        'client_secret' => 'google-client-secret',
    ]);
});

function fakeGoogleOauthUser(string $email, string $name): void
{
    $provider = Mockery::mock();
    $provider->shouldReceive('setConfig')->once()->andReturnSelf();
    $provider->shouldReceive('with')->once()->andReturnSelf();
    $provider->shouldReceive('user')->once()->andReturn((object) [
        'email' => $email,
        'name' => $name,
    ]);

    Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);
}

it('allows oauth self registration when password registration is disabled', function () {
    fakeGoogleOauthUser('root@example.com', 'Root User');

    $this->get(route('auth.callback', 'google'))
        ->assertRedirect('/');

    $user = User::find(0);

    expect($user)->not->toBeNull()
        ->and($user->email)->toBe('root@example.com')
        ->and($user->oauth_provider)->toBe('google')
        ->and($user->is_oauth_only)->toBeTrue()
        ->and($user->password)->toBeNull()
        ->and(InstanceSettings::find(0)?->is_registration_enabled)->toBeFalse();
});

it('blocks oauth self registration when oauth registration is also disabled', function () {
    InstanceSettings::find(0)?->update([
        'is_oauth_registration_enabled' => false,
    ]);

    fakeGoogleOauthUser('blocked@example.com', 'Blocked User');

    $this->from(route('login'))
        ->get(route('auth.callback', 'google'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors();

    expect(User::count())->toBe(0);
});

it('blocks password reset for oauth only accounts', function () {
    $user = User::factory()->create([
        'email' => 'oauth-only@example.com',
        'password' => null,
        'oauth_provider' => 'google',
        'is_oauth_only' => true,
    ]);

    Password::shouldReceive('broker')->never();

    $this->from('/forgot-password')
        ->post(route('password.forgot'), [
            'email' => $user->email,
        ])
        ->assertRedirect('/forgot-password')
        ->assertSessionHasErrors('email');
});
