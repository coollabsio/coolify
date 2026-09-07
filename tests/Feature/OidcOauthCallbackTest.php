<?php

use App\Auth\Oidc\OidcUser;
use App\Models\InstanceSettings;
use App\Models\OauthSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;
use Laravel\Socialite\Facades\Socialite;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.driver', 'file');

    InstanceSettings::forceCreate([
        'id' => 0,
        'is_registration_enabled' => false,
    ]);

    OauthSetting::query()->updateOrCreate(
        ['provider' => 'oidc'],
        [
            'enabled' => true,
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'base_url' => 'https://idp.example.com',
            'redirect_uri' => 'https://coolify.example.com/auth/oidc/callback',
            'scopes' => 'openid email profile',
        ]
    );

    Once::flush();
});

function fakeOidcSocialiteUser(array $claims = []): void
{
    $user = (new OidcUser)->setRaw(array_merge([
        'iss' => 'https://idp.example.com',
        'sub' => 'oidc-user-1',
        'email' => 'user@example.com',
        'email_verified' => true,
        'name' => 'OIDC User',
    ], $claims))->map([
        'id' => $claims['sub'] ?? 'oidc-user-1',
        'name' => $claims['name'] ?? 'OIDC User',
        'email' => $claims['email'] ?? 'user@example.com',
    ]);

    $provider = Mockery::mock();
    $provider->shouldReceive('setConfig')->andReturnSelf();
    $provider->shouldReceive('user')->andReturn($user);

    Socialite::shouldReceive('driver')->with('oidc')->andReturn($provider);
}

it('logs in an existing user through the generic oidc provider', function () {
    $user = User::factory()->create([
        'email' => 'username@example.edu',
    ]);

    fakeOidcSocialiteUser([
        'email' => 'UserName@example.edu',
        'name' => 'Example User',
        'sub' => 'oidc-user-1',
    ]);

    $response = $this->get(route('auth.callback', 'oidc'));

    $response->assertRedirect('/');
    $this->assertAuthenticatedAs($user);
    expect(User::count())->toBe(1);
});

it('creates a user from oidc when registration is enabled', function () {
    InstanceSettings::query()->where('id', 0)->update([
        'is_registration_enabled' => true,
    ]);
    Once::flush();

    fakeOidcSocialiteUser([
        'email' => 'new-oidc@example.com',
        'name' => 'New OIDC User',
        'sub' => 'oidc-new',
    ]);

    $response = $this->get(route('auth.callback', 'oidc'));

    $response->assertRedirect('/');
    $user = User::whereEmail('new-oidc@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('New OIDC User');
    $this->assertAuthenticatedAs($user);
});

it('rejects oidc logins when the provider does not return an email address', function () {
    InstanceSettings::query()->where('id', 0)->update([
        'is_registration_enabled' => true,
    ]);

    fakeOidcSocialiteUser([
        'email' => '   ',
        'name' => 'No Email',
        'sub' => 'oidc-no-email',
    ]);

    $response = $this->from('/login')->get(route('auth.callback', 'oidc'));

    $response->assertRedirect('/login');
    expect(User::count())->toBe(0);
});
