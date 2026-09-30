<?php

use App\Auth\Oidc\OidcUser;
use App\Http\Controllers\OauthController;
use App\Models\InstanceSettings;
use App\Models\OauthIdentity;
use App\Models\OauthSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Once;
use Illuminate\Support\ViewErrorBag;
use Laravel\Socialite\Facades\Socialite;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.store', 'array');
    config()->set('app.maintenance.driver', 'file');

    InstanceSettings::forceCreate([
        'id' => 0,
        'is_registration_enabled' => false,
    ]);

    Once::flush();

    OauthSetting::create([
        'provider' => 'google',
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'redirect_uri' => 'https://coolify.example.com/auth/google/callback',
        'tenant' => 'example.com',
        'enabled' => true,
    ]);
});

function linkGoogleIdentity(User $user, string $providerUserId): OauthIdentity
{
    return OauthIdentity::create([
        'user_id' => $user->id,
        'provider' => 'google',
        'issuer' => 'google',
        'provider_user_id' => $providerUserId,
        'email' => $user->email,
    ]);
}

function fakeGoogleConfirmation(string $providerUserId, string $email): void
{
    $provider = Mockery::mock();
    $provider->shouldReceive('setConfig')->andReturnSelf();
    $provider->shouldReceive('with')->andReturnSelf();
    $provider->shouldReceive('user')->andReturn((object) [
        'email' => $email,
        'name' => 'Provider User',
        'id' => $providerUserId,
        'user' => ['email_verified' => true, 'hd' => 'example.com'],
    ]);

    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
}

function pendingConfirmationFor(User $user, string $provider = 'google', string $returnTo = 'http://localhost/servers'): array
{
    return [OauthController::PASSWORD_CONFIRMATION_SESSION_KEY => [
        'user_id' => $user->id,
        'provider' => $provider,
        'return_to' => $returnTo,
    ]];
}

describe('confirmation requirement', function () {
    it('requires password confirmation for a user with a password and a linked identity', function () {
        $user = User::factory()->create(['password' => Hash::make('secret-password')]);
        linkGoogleIdentity($user, 'google-id');
        $this->actingAs($user);

        expect($user->requiresPasswordConfirmation())->toBeTrue()
            ->and(shouldSkipPasswordConfirmation())->toBeFalse()
            ->and(verifyPasswordConfirmation('wrong-password'))->toBeFalse()
            ->and(verifyPasswordConfirmation('secret-password'))->toBeTrue();
    });

    it('requires confirmation for a user with a linked identity and no password', function () {
        $user = User::factory()->create();
        $user->forceFill(['password' => null])->save();
        linkGoogleIdentity($user, 'google-id');
        $this->actingAs($user);

        expect($user->requiresPasswordConfirmation())->toBeTrue()
            ->and(shouldSkipPasswordConfirmation())->toBeFalse()
            ->and(verifyPasswordConfirmation(''))->toBeFalse();
    });

    it('keeps skipping confirmation for a user without a password and without an identity', function () {
        $user = User::factory()->create();
        $user->forceFill(['password' => null])->save();
        $this->actingAs($user);

        expect($user->requiresPasswordConfirmation())->toBeFalse()
            ->and(shouldSkipPasswordConfirmation())->toBeTrue();
    });

    it('accepts a recent confirmation from the session', function () {
        $user = User::factory()->create();
        linkGoogleIdentity($user, 'google-id');
        $this->actingAs($user);

        session()->put('auth.password_confirmed_at', now()->unix());

        expect(shouldSkipPasswordConfirmation())->toBeTrue()
            ->and(verifyPasswordConfirmation(''))->toBeTrue();
    });

    it('rejects an expired confirmation from the session', function () {
        $user = User::factory()->create();
        linkGoogleIdentity($user, 'google-id');
        $this->actingAs($user);

        session()->put('auth.password_confirmed_at', now()->subSeconds(config('auth.password_timeout') + 1)->unix());

        expect(shouldSkipPasswordConfirmation())->toBeFalse()
            ->and(verifyPasswordConfirmation(''))->toBeFalse();
    });
});

describe('confirmation redirect', function () {
    it('requires an authenticated user', function () {
        $this->get(route('auth.confirm', 'google'))->assertRedirect(route('login'));
    });

    it('rejects a user without an identity for the provider', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('auth.confirm', 'google'))->assertForbidden();

        expect(session(OauthController::PASSWORD_CONFIRMATION_SESSION_KEY))->toBeNull();
    });

    it('rejects a disabled provider', function () {
        $user = User::factory()->create();
        linkGoogleIdentity($user, 'google-id');
        OauthSetting::where('provider', 'google')->update(['enabled' => false]);

        $this->actingAs($user)->get(route('auth.confirm', 'google'))->assertForbidden();
    });

    it('redirects to the provider with re-authentication parameters and remembers the pending confirmation', function () {
        $user = User::factory()->create();
        linkGoogleIdentity($user, 'google-id');

        $provider = Mockery::mock();
        $provider->shouldReceive('setConfig')->andReturnSelf();
        $provider->shouldReceive('with')->once()->with(['hd' => 'example.com'])->andReturnSelf();
        $provider->shouldReceive('with')->once()->with([
            'hd' => 'example.com',
            'prompt' => 'select_account',
            'max_age' => 0,
        ])->andReturnSelf();
        $provider->shouldReceive('redirect')->once()->andReturn(redirect('https://accounts.google.com/o/oauth2/auth'));
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->actingAs($user)
            ->from('/servers')
            ->get(route('auth.confirm', 'google'))
            ->assertRedirect('https://accounts.google.com/o/oauth2/auth');

        expect(session(OauthController::PASSWORD_CONFIRMATION_SESSION_KEY))->toBe([
            'user_id' => $user->id,
            'provider' => 'google',
            'return_to' => url('/servers'),
        ]);
    });

    it('does not remember an external return URL', function () {
        $user = User::factory()->create();
        linkGoogleIdentity($user, 'google-id');
        $provider = Mockery::mock();
        $provider->shouldReceive('setConfig')->andReturnSelf();
        $provider->shouldReceive('with')->andReturnSelf();
        $provider->shouldReceive('redirect')->andReturn(redirect('https://accounts.google.com/o/oauth2/auth'));
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->actingAs($user)
            ->withHeader('referer', 'https://evil.example.com/phish')
            ->get(route('auth.confirm', 'google'));

        expect(session(OauthController::PASSWORD_CONFIRMATION_SESSION_KEY.'.return_to'))->toBe(url('/'));
    });
});

describe('confirmation callback', function () {
    it('marks the session as password confirmed when the linked identity of the same user returns', function () {
        $user = User::factory()->create(['email' => 'owner@example.com']);
        $identity = linkGoogleIdentity($user, 'owner-google-id');
        fakeGoogleConfirmation('owner-google-id', 'owner@example.com');

        $this->actingAs($user)
            ->withSession(pendingConfirmationFor($user))
            ->get(route('auth.callback', 'google'))
            ->assertRedirect('http://localhost/servers');

        $this->assertAuthenticatedAs($user);
        expect(session('auth.password_confirmed_at'))->toBeInt()
            ->and(session(OauthController::PASSWORD_CONFIRMATION_SESSION_KEY))->toBeNull()
            ->and(shouldSkipPasswordConfirmation())->toBeTrue()
            ->and($identity->fresh()->user_id)->toBe($user->id);
    });

    it('rejects the identity of another user and does not switch users', function () {
        $user = User::factory()->create(['email' => 'owner@example.com']);
        linkGoogleIdentity($user, 'owner-google-id');
        $otherUser = User::factory()->create(['email' => 'other@example.com']);
        linkGoogleIdentity($otherUser, 'other-google-id');
        fakeGoogleConfirmation('other-google-id', 'other@example.com');

        $this->actingAs($user)
            ->withSession(pendingConfirmationFor($user))
            ->get(route('auth.callback', 'google'))
            ->assertForbidden();

        $this->assertAuthenticatedAs($user);
        expect(session('auth.password_confirmed_at'))->toBeNull();
    });

    it('rejects an unknown identity without linking or creating a user', function () {
        InstanceSettings::query()->update(['is_registration_enabled' => true]);
        $user = User::factory()->create(['email' => 'owner@example.com']);
        linkGoogleIdentity($user, 'owner-google-id');
        fakeGoogleConfirmation('unknown-google-id', 'owner@example.com');

        $this->actingAs($user)
            ->withSession(pendingConfirmationFor($user))
            ->get(route('auth.callback', 'google'))
            ->assertForbidden();

        $this->assertAuthenticatedAs($user);
        expect(session('auth.password_confirmed_at'))->toBeNull()
            ->and(OauthIdentity::where('provider_user_id', 'unknown-google-id')->exists())->toBeFalse()
            ->and(User::count())->toBe(1);
    });

    it('rejects a pending confirmation that belongs to another user', function () {
        $user = User::factory()->create(['email' => 'owner@example.com']);
        linkGoogleIdentity($user, 'owner-google-id');
        $otherUser = User::factory()->create(['email' => 'other@example.com']);
        linkGoogleIdentity($otherUser, 'other-google-id');
        fakeGoogleConfirmation('other-google-id', 'other@example.com');

        $this->actingAs($user)
            ->withSession(pendingConfirmationFor($otherUser))
            ->get(route('auth.callback', 'google'))
            ->assertForbidden();

        $this->assertAuthenticatedAs($user);
        expect(session('auth.password_confirmed_at'))->toBeNull();
    });

    it('rejects a pending confirmation for a different provider', function () {
        OauthSetting::create([
            'provider' => 'github',
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'enabled' => true,
        ]);
        $user = User::factory()->create(['email' => 'owner@example.com']);
        linkGoogleIdentity($user, 'owner-google-id');
        fakeGoogleConfirmation('owner-google-id', 'owner@example.com');

        $this->actingAs($user)
            ->withSession(pendingConfirmationFor($user, 'github'))
            ->get(route('auth.callback', 'google'))
            ->assertForbidden();

        expect(session('auth.password_confirmed_at'))->toBeNull();
    });

    it('confirms an OIDC identity by issuer and subject', function () {
        OauthSetting::create([
            'provider' => 'oidc',
            'enabled' => true,
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'base_url' => 'https://idp.example.com',
            'redirect_uri' => 'https://coolify.example.com/auth/oidc/callback',
        ]);
        $user = User::factory()->create(['email' => 'oidc@example.com']);
        OauthIdentity::create([
            'user_id' => $user->id,
            'provider' => 'oidc',
            'issuer' => 'https://idp.example.com',
            'provider_user_id' => 'oidc-subject',
            'email' => $user->email,
        ]);
        $oidcUser = (new OidcUser)->setRaw([
            'iss' => 'https://idp.example.com',
            'sub' => 'oidc-subject',
            'email' => 'oidc@example.com',
            'email_verified' => true,
        ])->map(['id' => 'oidc-subject', 'email' => 'oidc@example.com', 'name' => 'OIDC User']);
        $provider = Mockery::mock();
        $provider->shouldReceive('setConfig')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($oidcUser);
        Socialite::shouldReceive('driver')->with('oidc')->andReturn($provider);

        $this->actingAs($user)
            ->withSession(pendingConfirmationFor($user, 'oidc'))
            ->get(route('auth.callback', 'oidc'))
            ->assertRedirect('http://localhost/servers');

        expect(session('auth.password_confirmed_at'))->toBeInt();
    });

    it('keeps the normal login flow when no confirmation is pending', function () {
        $user = User::factory()->create(['email' => 'owner@example.com']);
        linkGoogleIdentity($user, 'owner-google-id');
        fakeGoogleConfirmation('owner-google-id', 'owner@example.com');

        $this->get(route('auth.callback', 'google'))->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
        expect(session('auth.password_confirmed_at'))->toBeNull();
    });
});

describe('confirmation modal', function () {
    function renderConfirmationModal(): string
    {
        view()->share('errors', new ViewErrorBag);

        return Blade::render('<x-modal-confirmation title="Delete" buttonTitle="Delete" submitAction="delete" :actions="[]" confirmationText="x" />');
    }

    it('offers OAuth confirmation next to the password for a user with a linked identity', function () {
        $user = User::factory()->create();
        linkGoogleIdentity($user, 'google-id');
        $this->actingAs($user);

        $html = renderConfirmationModal();

        expect($html)->toContain('Confirm with Google')
            ->and($html)->toContain(route('auth.confirm', 'google'))
            ->and($html)->toContain('type="password"');
    });

    it('offers only OAuth confirmation for a user without a password', function () {
        $user = User::factory()->create();
        $user->forceFill(['password' => null])->save();
        linkGoogleIdentity($user, 'google-id');
        $this->actingAs($user);

        $html = renderConfirmationModal();

        expect($html)->toContain('Confirm with Google')
            ->and($html)->not->toContain('type="password"');
    });

    it('does not offer OAuth confirmation for a user without an identity', function () {
        $user = User::factory()->create();
        $this->actingAs($user);

        $html = renderConfirmationModal();

        expect($html)->not->toContain('Confirm with Google')
            ->and($html)->toContain('type="password"');
    });
});
