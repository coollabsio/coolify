<?php

use App\Auth\Oidc\OidcUser;
use App\Models\InstanceSettings;
use App\Models\OauthIdentity;
use App\Models\OauthSetting;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Once;
use Laravel\Socialite\Facades\Socialite;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.driver', 'file');

    InstanceSettings::forceCreate([
        'id' => 0,
        'is_registration_enabled' => false,
    ]);

    Once::flush();

    OauthSetting::create([
        'provider' => 'oidc',
        'enabled' => true,
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'base_url' => 'https://idp.example.com',
        'redirect_uri' => 'https://coolify.example.com/auth/oidc/callback',
        'allow_registration' => false,
    ]);
});

function fakeOidcProvider(array $claims = []): void
{
    $user = (new OidcUser)->setRaw(array_merge([
        'iss' => 'https://idp.example.com',
        'sub' => 'okta-user-1',
        'email' => 'user@example.com',
        'email_verified' => true,
        'name' => 'Okta User',
    ], $claims))->map([
        'id' => $claims['sub'] ?? 'okta-user-1',
        'name' => $claims['name'] ?? 'Okta User',
        'email' => $claims['email'] ?? 'user@example.com',
    ]);

    $provider = Mockery::mock();
    $provider->shouldReceive('setConfig')->andReturnSelf();
    $provider->shouldReceive('user')->andReturn($user);

    Socialite::shouldReceive('driver')->with('oidc')->andReturn($provider);
}

it('logs in a user through an existing oidc identity', function () {
    $user = User::factory()->create(['email' => 'existing@example.com']);
    OauthIdentity::create([
        'user_id' => $user->id,
        'provider' => 'oidc',
        'issuer' => 'https://idp.example.com',
        'provider_user_id' => 'okta-user-1',
        'email' => 'existing@example.com',
    ]);

    fakeOidcProvider(['email' => 'existing@example.com']);

    $response = $this->get(route('auth.callback', 'oidc'));

    $response->assertRedirect('/');
    $this->assertAuthenticatedAs($user);
});

it('creates a new oidc user when provider registration is allowed while normal registration is disabled', function () {
    OauthSetting::where('provider', 'oidc')->update(['allow_registration' => true]);

    fakeOidcProvider(['email' => 'newuser@example.com']);

    $response = $this->get(route('auth.callback', 'oidc'));

    $response->assertRedirect('/');
    $user = User::whereEmail('newuser@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->password)->not->toBeNull();
    $this->assertAuthenticatedAs($user);
    $this->assertDatabaseHas('oauth_identities', [
        'user_id' => $user->id,
        'provider' => 'oidc',
        'issuer' => 'https://idp.example.com',
        'provider_user_id' => 'okta-user-1',
    ]);
});

it('rejects linking an unverified oidc email to an existing local account', function () {
    $user = User::factory()->create(['email' => 'victim@example.com']);

    fakeOidcProvider(['email' => 'victim@example.com', 'email_verified' => false]);

    $response = $this->from('/login')->get(route('auth.callback', 'oidc'));

    $response->assertRedirect('/login');
    $this->assertGuest();
    $this->assertDatabaseMissing('oauth_identities', [
        'user_id' => $user->id,
        'provider' => 'oidc',
    ]);
});

it('rejects new oidc users when neither normal nor provider registration is enabled', function () {
    fakeOidcProvider(['email' => 'blocked@example.com']);

    $response = $this->from('/login')->get(route('auth.callback', 'oidc'));

    $response->assertRedirect('/login');
    expect(User::whereEmail('blocked@example.com')->exists())->toBeFalse();
});

it('creates the root user when oidc provisions the first account', function () {
    Team::forceCreate(['id' => 0, 'name' => 'Root Team', 'personal_team' => true]);
    OauthSetting::where('provider', 'oidc')->update(['allow_registration' => true]);

    fakeOidcProvider(['email' => 'root@example.com', 'name' => 'Root User']);

    $response = $this->get(route('auth.callback', 'oidc'));

    $response->assertRedirect('/');
    $this->assertDatabaseHas('users', ['id' => 0, 'email' => 'root@example.com']);
    $this->assertDatabaseHas('team_user', ['team_id' => 0, 'user_id' => 0, 'role' => 'owner']);
    expect(InstanceSettings::find(0)->is_registration_enabled)->toBeFalse();
});

it('rejects callbacks for disabled oidc provider', function () {
    OauthSetting::where('provider', 'oidc')->update(['enabled' => false]);

    $response = $this->from('/login')->get(route('auth.callback', 'oidc'));

    $response->assertRedirect('/login');
});

it('logs callback failures with diagnostic context', function () {
    Log::spy();

    $provider = Mockery::mock();
    $provider->shouldReceive('setConfig')->andReturnSelf();
    $provider->shouldReceive('user')->andThrow(new RuntimeException('Token exchange failed'));
    Socialite::shouldReceive('driver')->with('oidc')->andReturn($provider);

    $response = $this->from('/login')->get(route('auth.callback', ['provider' => 'oidc', 'code' => 'secret-code', 'state' => 'state-value']));

    $response->assertRedirect('/login');
    Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context) {
        return $message === 'OAuth callback failed.'
            && $context['provider'] === 'oidc'
            && $context['exception_class'] === RuntimeException::class
            && $context['exception_message'] === 'Token exchange failed'
            && $context['has_code'] === true
            && $context['has_state'] === true
            && $context['exception'] instanceof RuntimeException;
    });
});
