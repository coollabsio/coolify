<?php

use App\Models\InstanceSettings;
use App\Models\OauthSetting;
use App\Models\OauthUserLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();

    (new InstanceSettings)->forceFill([
        'id' => 0,
        'is_registration_enabled' => false,
    ])->save();

    OauthSetting::create([
        'provider' => 'google',
        'enabled' => true,
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'redirect_uri' => 'https://coolify.example.com/auth/google/callback',
        'tenant' => 'example.com',
    ]);
});

function mockGoogleProvider(array $payload): void
{
    $oauthUser = (object) array_merge([
        'email' => 'username@example.edu',
        'name' => 'Example User',
        'id' => 'google-user-id',
    ], $payload);

    $oauthUser->user = $payload['user'] ?? ['email_verified' => true];

    $provider = Mockery::mock();
    $provider->shouldReceive('setConfig')->once()->andReturnSelf();
    $provider->shouldReceive('with')->once()->with(['hd' => 'example.com'])->andReturnSelf();
    $provider->shouldReceive('user')->once()->andReturn($oauthUser);

    Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);
}

it('logs in a linked user when the oauth provider returns a mixed-case email', function () {
    config()->set('app.maintenance.driver', 'file');

    $user = User::factory()->create([
        'email' => 'username@example.edu',
    ]);
    OauthUserLink::create([
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_user_id' => 'google-user-id',
    ]);

    mockGoogleProvider([
        'email' => 'UserName@example.edu',
        'user' => ['email_verified' => true],
    ]);

    $response = $this->get(route('auth.callback', 'google'));

    $response->assertRedirect('/');
    $this->assertAuthenticatedAs($user);
    expect(User::count())->toBe(1);
});

it('rejects oauth logins when the provider does not return an email address', function (?string $providerEmail) {
    config()->set('app.maintenance.driver', 'file');
    InstanceSettings::find(0)->update(['is_registration_enabled' => true]);

    mockGoogleProvider([
        'email' => $providerEmail,
        'user' => ['email_verified' => true],
    ]);

    $response = $this->from('/login')->get(route('auth.callback', 'google'));

    $response->assertStatus(403);
    expect(User::count())->toBe(0);
})->with([
    'null email' => [null],
    'blank email' => ['   '],
]);

it('rejects oauth login when the provider does not assert email_verified=true', function (array $rawUser) {
    InstanceSettings::firstOrCreate(['id' => 0], ['is_registration_enabled' => false])
        ->update(['is_registration_enabled' => true]);

    mockGoogleProvider([
        'email' => 'username@example.edu',
        'user' => $rawUser,
    ]);

    $response = $this->get(route('auth.callback', 'google'));

    $response->assertStatus(403);
    $this->assertGuest();
})->with([
    'missing claim' => [[]],
    'explicit false' => [['email_verified' => false]],
    'string false' => [['email_verified' => 'false']],
]);

it('rejects oauth login when an account exists with that email and a password but no link', function () {
    $user = User::factory()->create([
        'email' => 'username@example.edu',
        'password' => Hash::make('SomePassword!1'),
    ]);

    mockGoogleProvider([
        'email' => 'username@example.edu',
        'user' => ['email_verified' => true],
    ]);

    $response = $this->get(route('auth.callback', 'google'));

    $response->assertStatus(403);
    $this->assertGuest();
    expect(OauthUserLink::count())->toBe(0);
    $this->assertDatabaseHas('users', ['id' => $user->id]);
});

it('auto-links a pre-existing password-less user on first verified oauth login', function () {
    $user = User::factory()->create([
        'email' => 'oauth-only@example.edu',
        'password' => null,
    ]);

    $oauthUser = (object) [
        'email' => 'oauth-only@example.edu',
        'name' => 'OAuth Only',
        'id' => 'google-uid-pwlessl',
        'user' => ['email_verified' => true],
    ];

    $provider = Mockery::mock();
    $provider->shouldReceive('setConfig')->once()->andReturnSelf();
    $provider->shouldReceive('with')->once()->with(['hd' => 'example.com'])->andReturnSelf();
    $provider->shouldReceive('user')->once()->andReturn($oauthUser);
    Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);

    $response = $this->get(route('auth.callback', 'google'));

    $response->assertRedirect('/');
    $this->assertAuthenticatedAs($user);
    expect(OauthUserLink::where('user_id', $user->id)->count())->toBe(1);
});

it('rejects oauth login for a brand new user when registration is disabled', function () {
    $oauthUser = (object) [
        'email' => 'new-user@example.edu',
        'name' => 'New User',
        'id' => 'google-uid-new',
        'user' => ['email_verified' => true],
    ];

    $provider = Mockery::mock();
    $provider->shouldReceive('setConfig')->once()->andReturnSelf();
    $provider->shouldReceive('with')->once()->with(['hd' => 'example.com'])->andReturnSelf();
    $provider->shouldReceive('user')->once()->andReturn($oauthUser);
    Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);

    $response = $this->get(route('auth.callback', 'google'));

    $response->assertStatus(403);
    $this->assertGuest();
    expect(User::count())->toBe(0);
});

it('creates a new user and link when registration is enabled', function () {
    InstanceSettings::firstOrCreate(['id' => 0], ['is_registration_enabled' => false])
        ->update(['is_registration_enabled' => true]);

    $oauthUser = (object) [
        'email' => 'fresh@example.edu',
        'name' => 'Fresh User',
        'id' => 'google-uid-fresh',
        'user' => ['email_verified' => true],
    ];

    $provider = Mockery::mock();
    $provider->shouldReceive('setConfig')->once()->andReturnSelf();
    $provider->shouldReceive('with')->once()->with(['hd' => 'example.com'])->andReturnSelf();
    $provider->shouldReceive('user')->once()->andReturn($oauthUser);
    Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);

    $response = $this->get(route('auth.callback', 'google'));

    $response->assertRedirect('/');
    expect(User::count())->toBe(1);
    expect(OauthUserLink::count())->toBe(1);
    $this->assertAuthenticated();
});

it('still authenticates linked users that have 2FA enrolled (oauth path bypasses Coolify 2FA by design)', function () {
    $user = User::factory()->create([
        'email' => 'twofa@example.edu',
        'two_factor_secret' => encrypt('secret'),
        'two_factor_confirmed_at' => now(),
    ]);
    OauthUserLink::create([
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_user_id' => 'google-uid-twofa',
    ]);

    $oauthUser = (object) [
        'email' => 'twofa@example.edu',
        'name' => 'TwoFA',
        'id' => 'google-uid-twofa',
        'user' => ['email_verified' => true],
    ];

    $provider = Mockery::mock();
    $provider->shouldReceive('setConfig')->once()->andReturnSelf();
    $provider->shouldReceive('with')->once()->with(['hd' => 'example.com'])->andReturnSelf();
    $provider->shouldReceive('user')->once()->andReturn($oauthUser);
    Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);

    $response = $this->get(route('auth.callback', 'google'));

    $response->assertRedirect('/');
    $this->assertAuthenticatedAs($user);
});
