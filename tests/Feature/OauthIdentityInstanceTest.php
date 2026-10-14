<?php

use App\Models\InstanceSettings;
use App\Models\OauthIdentity;
use App\Models\OauthSetting;
use App\Models\User;
use App\Services\Auth\OauthIdentityIssuer;
use App\Services\Auth\OauthLoginService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Once;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

const OAUTH_REKEY_MIGRATION = 'migrations/2026_09_30_120000_rekey_oauth_identity_issuers_to_provider_instance.php';

beforeEach(function () {
    InstanceSettings::forceCreate([
        'id' => 0,
        'is_registration_enabled' => false,
    ]);

    Once::flush();
});

function instanceOauthSetting(string $provider, array $attributes = []): OauthSetting
{
    return OauthSetting::create([
        'provider' => $provider,
        'client_id' => "{$provider}-client-id",
        'client_secret' => "{$provider}-client-secret",
        'enabled' => true,
        ...$attributes,
    ]);
}

function linkInstanceIdentity(User $user, string $provider, string $issuer, string $providerUserId): OauthIdentity
{
    return OauthIdentity::create([
        'user_id' => $user->id,
        'provider' => $provider,
        'issuer' => $issuer,
        'provider_user_id' => $providerUserId,
        'email' => $user->email,
    ]);
}

function instanceOauthUser(string|int $id, string $email, array $rawClaims = []): object
{
    return (object) [
        'id' => $id,
        'email' => $email,
        'name' => 'Provider User',
        'user' => $rawClaims,
    ];
}

describe('instance key', function () {
    it('normalizes the base URL of self-hostable providers', function (string $provider, ?string $baseUrl, ?string $expected) {
        expect(OauthIdentityIssuer::forProvider($provider, $baseUrl, null))->toBe($expected);
    })->with([
        'gitlab.com default' => ['gitlab', null, 'https://gitlab.com'],
        'gitlab empty base url' => ['gitlab', '', 'https://gitlab.com'],
        'trailing slash' => ['gitlab', 'https://gitlab.example.com/', 'https://gitlab.example.com'],
        'uppercase scheme and host' => ['gitlab', 'HTTPS://GitLab.Example.COM', 'https://gitlab.example.com'],
        'default https port' => ['authentik', 'https://auth.example.com:443/', 'https://auth.example.com'],
        'default http port' => ['authentik', 'http://auth.example.com:80', 'http://auth.example.com'],
        'custom port kept' => ['authentik', 'https://auth.example.com:8443', 'https://auth.example.com:8443'],
        'path kept without trailing slash' => ['gitlab', 'https://example.com/GitLab/', 'https://example.com/GitLab'],
        'query and fragment dropped' => ['zitadel', 'https://id.example.com/?a=b#c', 'https://id.example.com'],
        'missing scheme' => ['clerk', 'clerk.example.com', 'https://clerk.example.com'],
        'authentik without base url' => ['authentik', null, null],
        'zitadel without base url' => ['zitadel', '  ', null],
    ]);

    it('uses the lowercased Azure tenant and one stable key for multi-tenant endpoints', function (?string $tenant, string $expected) {
        expect(OauthIdentityIssuer::forProvider('azure', null, $tenant))->toBe($expected);
    })->with([
        'tenant id' => ['1F2E3D4C-0000-4000-8000-ABCDEF123456', '1f2e3d4c-0000-4000-8000-abcdef123456'],
        'tenant domain' => [' Contoso.onmicrosoft.com ', 'contoso.onmicrosoft.com'],
        'common' => ['common', 'azure'],
        'organizations' => ['Organizations', 'azure'],
        'consumers' => ['consumers', 'azure'],
        'no tenant' => [null, 'azure'],
    ]);

    it('keeps the provider name for single SaaS providers', function (string $provider) {
        expect(OauthIdentityIssuer::forProvider($provider, 'https://ignored.example.com', 'ignored.example.com'))->toBe($provider);
    })->with(['github', 'bitbucket', 'discord', 'google', 'infomaniak']);

    it('does not compute a key for OIDC, which uses the iss claim', function () {
        expect(OauthIdentityIssuer::forProvider('oidc', 'https://idp.example.com', null))->toBeNull();
    });
});

describe('login', function () {
    it('does not log in as the linked user when the same subject comes from another instance', function (string $provider, ?string $linkedBaseUrl, string $otherBaseUrl) {
        $victim = User::factory()->create(['email' => 'victim@example.com']);
        $setting = instanceOauthSetting($provider, ['base_url' => $linkedBaseUrl]);
        $verifiedClaims = ['confirmed_at' => '2026-01-01T00:00:00Z', 'email_verified' => true];
        $service = app(OauthLoginService::class);

        expect($service->login($provider, instanceOauthUser(1, 'victim@example.com', $verifiedClaims), $setting)->is($victim))->toBeTrue();
        Auth::logout();

        $setting->update(['base_url' => $otherBaseUrl]);

        expect(fn () => $service->login($provider, instanceOauthUser(1, 'attacker@example.com', $verifiedClaims), $setting->refresh()))
            ->toThrow(HttpException::class);

        expect(Auth::check())->toBeFalse()
            ->and(OauthIdentity::count())->toBe(1);
    })->with([
        'self-hosted gitlab' => ['gitlab', 'https://gitlab.example.com', 'https://gitlab.attacker.example'],
        'gitlab.com to self-hosted' => ['gitlab', null, 'https://gitlab.attacker.example'],
        'authentik' => ['authentik', 'https://auth.example.com', 'https://auth.attacker.example'],
        'zitadel' => ['zitadel', 'https://id.example.com', 'https://id.attacker.example'],
        'clerk' => ['clerk', 'https://clerk.example.com', 'https://clerk.attacker.example'],
    ]);

    it('does not log in as the linked user with the same subject and email from another instance', function () {
        $victim = User::factory()->create(['email' => 'victim@example.com']);
        $setting = instanceOauthSetting('gitlab', ['base_url' => 'https://gitlab.example.com']);
        $claims = ['confirmed_at' => '2026-01-01T00:00:00Z'];
        $service = app(OauthLoginService::class);

        $service->login('gitlab', instanceOauthUser(1, 'victim@example.com', $claims), $setting);
        Auth::logout();
        $setting->update(['base_url' => 'https://gitlab.attacker.example']);

        expect(fn () => $service->login('gitlab', instanceOauthUser(1, 'victim@example.com', $claims), $setting->refresh()))
            ->toThrow(HttpException::class);

        expect(Auth::check())->toBeFalse();
    });

    it('logs in the linked user from the same instance when the base URL is written differently', function () {
        $user = User::factory()->create(['email' => 'user@example.com']);
        linkInstanceIdentity($user, 'gitlab', 'https://gitlab.example.com', '1');
        $setting = instanceOauthSetting('gitlab', ['base_url' => 'HTTPS://GitLab.Example.com:443/']);

        $resolvedUser = app(OauthLoginService::class)->login('gitlab', instanceOauthUser(1, 'user@example.com'), $setting);

        expect($resolvedUser->is($user))->toBeTrue();
        $this->assertAuthenticatedAs($user);
    });

    it('does not log in as the linked user after the Azure tenant changes', function () {
        $user = User::factory()->create(['email' => 'user@contoso.com']);
        $setting = instanceOauthSetting('azure', ['tenant' => 'contoso-tenant']);
        $azureUser = instanceOauthUser('azure-object-id', 'user@contoso.com', ['userPrincipalName' => 'user@contoso.com']);
        $service = app(OauthLoginService::class);

        $service->login('azure', $azureUser, $setting);
        Auth::logout();
        $setting->update(['tenant' => 'other-tenant']);

        expect(fn () => $service->login('azure', $azureUser, $setting->refresh()))->toThrow(HttpException::class);

        expect(Auth::check())->toBeFalse();
    });

    it('logs in the linked Azure user from the same tenant', function () {
        $user = User::factory()->create(['email' => 'user@contoso.com']);
        linkInstanceIdentity($user, 'azure', 'contoso-tenant', 'azure-object-id');
        $setting = instanceOauthSetting('azure', ['tenant' => 'Contoso-Tenant']);

        $resolvedUser = app(OauthLoginService::class)->login('azure', instanceOauthUser('azure-object-id', 'user@contoso.com'), $setting);

        expect($resolvedUser->is($user))->toBeTrue();
    });

    it('stores new identities under the normalized instance key', function () {
        instanceSettings()->update(['is_registration_enabled' => true]);
        $setting = instanceOauthSetting('gitlab', ['base_url' => 'https://GitLab.Example.com/']);

        $user = app(OauthLoginService::class)->login('gitlab', instanceOauthUser(7, 'new@example.com', [
            'confirmed_at' => '2026-01-01T00:00:00Z',
        ]), $setting);

        $this->assertDatabaseHas('oauth_identities', [
            'user_id' => $user->id,
            'provider' => 'gitlab',
            'issuer' => 'https://gitlab.example.com',
            'provider_user_id' => '7',
        ]);
    });

    it('keeps the provider name as the key for single SaaS providers', function () {
        $user = User::factory()->create(['email' => 'user@example.com']);
        linkInstanceIdentity($user, 'github', 'github', '99');
        $setting = instanceOauthSetting('github');

        $resolvedUser = app(OauthLoginService::class)->login('github', instanceOauthUser(99, 'user@example.com'), $setting);

        expect($resolvedUser->is($user))->toBeTrue()
            ->and(OauthIdentity::sole()->issuer)->toBe('github');
    });

    it('rejects login when a self-hostable provider has no base URL', function () {
        $user = User::factory()->create(['email' => 'user@example.com']);
        linkInstanceIdentity($user, 'authentik', 'authentik', 'subject');
        $setting = instanceOauthSetting('authentik');

        expect(fn () => app(OauthLoginService::class)->login('authentik', instanceOauthUser('subject', 'user@example.com'), $setting))
            ->toThrow(HttpException::class);

        expect(Auth::check())->toBeFalse();
    });
});

describe('confirmation', function () {
    it('has no OAuth re-authentication route for confirming destructive actions', function () {
        $user = User::factory()->create(['email' => 'user@example.com']);
        linkInstanceIdentity($user, 'gitlab', 'https://gitlab.example.com', '1');
        instanceOauthSetting('gitlab', ['base_url' => 'https://gitlab.example.com']);

        expect(Route::has('auth.confirm'))->toBeFalse();

        // The path falls through to the catch-all route and stays inside Coolify.
        $response = $this->actingAs($user)->get('/auth/gitlab/confirm');

        $response->assertRedirect();
        expect($response->headers->get('Location'))->toStartWith(url('/'));
    });
});

describe('backfill migration', function () {
    it('rekeys non-OIDC identities to the currently configured instance', function () {
        $user = User::factory()->create();
        instanceOauthSetting('gitlab', ['base_url' => 'https://GitLab.Example.com:443/']);
        instanceOauthSetting('authentik', ['base_url' => 'https://auth.example.com/']);
        instanceOauthSetting('azure', ['tenant' => 'Contoso-Tenant']);
        instanceOauthSetting('github');
        instanceOauthSetting('oidc', ['base_url' => 'https://idp.example.com']);

        $gitlab = linkInstanceIdentity($user, 'gitlab', 'gitlab', '1');
        $authentik = linkInstanceIdentity($user, 'authentik', 'authentik', '2');
        $azure = linkInstanceIdentity($user, 'azure', 'azure', '3');
        $github = linkInstanceIdentity($user, 'github', 'github', '4');
        $oidc = linkInstanceIdentity($user, 'oidc', 'https://idp.example.com', '5');

        $migration = require database_path(OAUTH_REKEY_MIGRATION);
        $migration->up();

        expect($gitlab->refresh()->issuer)->toBe('https://gitlab.example.com')
            ->and($authentik->refresh()->issuer)->toBe('https://auth.example.com')
            ->and($azure->refresh()->issuer)->toBe('contoso-tenant')
            ->and($github->refresh()->issuer)->toBe('github')
            ->and($oidc->refresh()->issuer)->toBe('https://idp.example.com');
    });

    it('defaults GitLab identities to gitlab.com and leaves unconfigured providers untouched', function () {
        $user = User::factory()->create();
        instanceOauthSetting('gitlab');
        instanceOauthSetting('zitadel');

        $gitlab = linkInstanceIdentity($user, 'gitlab', 'gitlab', '1');
        $zitadel = linkInstanceIdentity($user, 'zitadel', 'zitadel', '2');

        $migration = require database_path(OAUTH_REKEY_MIGRATION);
        $migration->up();

        expect($gitlab->refresh()->issuer)->toBe('https://gitlab.com')
            ->and($zitadel->refresh()->issuer)->toBe('zitadel');
    });

    it('keeps existing logins working after the backfill', function () {
        $user = User::factory()->create(['email' => 'user@example.com']);
        $setting = instanceOauthSetting('gitlab', ['base_url' => 'https://gitlab.example.com']);
        linkInstanceIdentity($user, 'gitlab', 'gitlab', '1');

        $migration = require database_path(OAUTH_REKEY_MIGRATION);
        $migration->up();

        $resolvedUser = app(OauthLoginService::class)->login('gitlab', instanceOauthUser(1, 'user@example.com'), $setting);

        expect($resolvedUser->is($user))->toBeTrue();
    });
});

describe('rekey command', function () {
    it('moves identities from an old instance key to the current one', function () {
        $user = User::factory()->create(['email' => 'user@example.com']);
        instanceOauthSetting('gitlab', ['base_url' => 'https://gitlab.new.example']);
        $identity = linkInstanceIdentity($user, 'gitlab', 'https://gitlab.old.example', '1');
        $other = linkInstanceIdentity(User::factory()->create(), 'authentik', 'https://gitlab.old.example', '1');

        $this->artisan('oauth:rekey', ['provider' => 'gitlab', '--from' => 'https://GitLab.old.example/', '--force' => true])
            ->assertSuccessful();

        expect($identity->refresh()->issuer)->toBe('https://gitlab.new.example')
            ->and($other->refresh()->issuer)->toBe('https://gitlab.old.example');
    });

    it('accepts an explicit target key', function () {
        $user = User::factory()->create();
        instanceOauthSetting('azure', ['tenant' => 'new-tenant']);
        $identity = linkInstanceIdentity($user, 'azure', 'old-tenant', '1');

        $this->artisan('oauth:rekey', ['provider' => 'azure', '--from' => 'Old-Tenant', '--to' => 'Other-Tenant', '--force' => true])
            ->assertSuccessful();

        expect($identity->refresh()->issuer)->toBe('other-tenant');
    });

    it('does not rekey without confirmation', function () {
        $user = User::factory()->create();
        instanceOauthSetting('gitlab', ['base_url' => 'https://gitlab.new.example']);
        $identity = linkInstanceIdentity($user, 'gitlab', 'https://gitlab.old.example', '1');

        $this->artisan('oauth:rekey', ['provider' => 'gitlab', '--from' => 'https://gitlab.old.example'])
            ->expectsConfirmation('Move 1 gitlab identity from https://gitlab.old.example to https://gitlab.new.example?', 'no')
            ->assertFailed();

        expect($identity->refresh()->issuer)->toBe('https://gitlab.old.example');
    });
});
