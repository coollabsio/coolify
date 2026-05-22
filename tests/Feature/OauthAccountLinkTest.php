<?php

use App\Livewire\Profile\OauthLinks;
use App\Models\InstanceSettings;
use App\Models\OauthSetting;
use App\Models\OauthUserLink;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;
use Livewire\Livewire;

function makeUserWithoutBoarding(array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    Team::query()->whereIn('id', $user->teams->pluck('id'))->update(['show_boarding' => false]);
    $user->load('teams');

    return $user;
}

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

it('connects an oauth provider for the authenticated user', function () {
    $user = makeUserWithoutBoarding();
    $this->actingAs($user);

    Livewire::test(OauthLinks::class)
        ->call('connect', 'google')
        ->assertRedirect(route('auth.redirect', ['provider' => 'google']));

    expect(session('oauth.intent'))->toBe('link');
    expect((int) session('oauth.user_id'))->toBe($user->id);
});

it('links the oauth account when the link callback succeeds', function () {
    $user = makeUserWithoutBoarding();

    $oauthUser = (object) [
        'email' => $user->email,
        'name' => $user->name,
        'id' => 'google-uid-link',
        'user' => ['email_verified' => true],
    ];

    $provider = Mockery::mock();
    $provider->shouldReceive('setConfig')->once()->andReturnSelf();
    $provider->shouldReceive('with')->once()->with(['hd' => 'example.com'])->andReturnSelf();
    $provider->shouldReceive('user')->once()->andReturn($oauthUser);
    Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);

    $response = $this->actingAs($user)
        ->withSession([
            'oauth.intent' => 'link',
            'oauth.user_id' => $user->id,
        ])
        ->get(route('auth.callback', 'google'));

    $response->assertRedirect(route('profile'));
    expect(OauthUserLink::count())->toBe(1);
    expect(OauthUserLink::first()->user_id)->toBe($user->id);
});

it('rejects a link callback when the provider id is already linked to another user', function () {
    $userA = makeUserWithoutBoarding();
    $userB = makeUserWithoutBoarding();

    OauthUserLink::create([
        'user_id' => $userB->id,
        'provider' => 'google',
        'provider_user_id' => 'google-uid-shared',
    ]);

    $oauthUser = (object) [
        'email' => $userA->email,
        'name' => $userA->name,
        'id' => 'google-uid-shared',
        'user' => ['email_verified' => true],
    ];

    $provider = Mockery::mock();
    $provider->shouldReceive('setConfig')->once()->andReturnSelf();
    $provider->shouldReceive('with')->once()->with(['hd' => 'example.com'])->andReturnSelf();
    $provider->shouldReceive('user')->once()->andReturn($oauthUser);
    Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);

    $response = $this->actingAs($userA)
        ->withSession([
            'oauth.intent' => 'link',
            'oauth.user_id' => $userA->id,
        ])
        ->get(route('auth.callback', 'google'));

    $response->assertStatus(403);
    expect(OauthUserLink::count())->toBe(1);
    expect(OauthUserLink::first()->user_id)->toBe($userB->id);
});

it('rejects a link callback when the session user does not match the authenticated user', function () {
    $userA = makeUserWithoutBoarding();
    $userB = makeUserWithoutBoarding();

    $oauthUser = (object) [
        'email' => $userA->email,
        'name' => $userA->name,
        'id' => 'google-uid-bad-session',
        'user' => ['email_verified' => true],
    ];

    $provider = Mockery::mock();
    $provider->shouldReceive('setConfig')->once()->andReturnSelf();
    $provider->shouldReceive('with')->once()->with(['hd' => 'example.com'])->andReturnSelf();
    $provider->shouldReceive('user')->once()->andReturn($oauthUser);
    Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);

    $response = $this->actingAs($userA)
        ->withSession([
            'oauth.intent' => 'link',
            'oauth.user_id' => $userB->id,
        ])
        ->get(route('auth.callback', 'google'));

    $response->assertStatus(403);
    expect(OauthUserLink::count())->toBe(0);
});

it('disconnects an oauth provider for its owner', function () {
    $user = makeUserWithoutBoarding();
    $this->actingAs($user);

    $link = OauthUserLink::create([
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_user_id' => 'google-uid-mine',
    ]);

    Livewire::test(OauthLinks::class)
        ->call('disconnect', $link->id);

    expect(OauthUserLink::find($link->id))->toBeNull();
});

it('does not allow a user to disconnect another user oauth link', function () {
    $owner = makeUserWithoutBoarding();
    $attacker = makeUserWithoutBoarding();

    $link = OauthUserLink::create([
        'user_id' => $owner->id,
        'provider' => 'google',
        'provider_user_id' => 'google-uid-target',
    ]);

    $this->actingAs($attacker);

    Livewire::test(OauthLinks::class)
        ->call('disconnect', $link->id);

    expect(OauthUserLink::find($link->id))->not->toBeNull();
});

it('blocks disconnect when it is the last sign-in method for a password-less user', function () {
    $user = makeUserWithoutBoarding(['password' => null]);
    $this->actingAs($user);

    $link = OauthUserLink::create([
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_user_id' => 'google-uid-only',
    ]);

    Livewire::test(OauthLinks::class)
        ->call('disconnect', $link->id)
        ->assertDispatched('error');

    expect(OauthUserLink::find($link->id))->not->toBeNull();
});

it('allows disconnect when password-less user has multiple oauth links', function () {
    $user = makeUserWithoutBoarding(['password' => null]);
    $this->actingAs($user);

    OauthSetting::create([
        'provider' => 'github',
        'enabled' => true,
        'client_id' => 'gh-client-id',
        'client_secret' => 'gh-client-secret',
        'redirect_uri' => 'https://coolify.example.com/auth/github/callback',
        'tenant' => '',
    ]);

    $linkA = OauthUserLink::create([
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_user_id' => 'google-uid-multi-a',
    ]);

    $linkB = OauthUserLink::create([
        'user_id' => $user->id,
        'provider' => 'github',
        'provider_user_id' => 'github-uid-multi-b',
    ]);

    Livewire::test(OauthLinks::class)
        ->call('disconnect', $linkA->id)
        ->assertDispatched('success');

    expect(OauthUserLink::find($linkA->id))->toBeNull();
    expect(OauthUserLink::find($linkB->id))->not->toBeNull();
});

it('allows disconnect when user has a password even if it is their last oauth link', function () {
    $user = makeUserWithoutBoarding(['password' => Hash::make('SecurePass!1')]);
    $this->actingAs($user);

    $link = OauthUserLink::create([
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_user_id' => 'google-uid-has-pw',
    ]);

    Livewire::test(OauthLinks::class)
        ->call('disconnect', $link->id)
        ->assertDispatched('success');

    expect(OauthUserLink::find($link->id))->toBeNull();
});
