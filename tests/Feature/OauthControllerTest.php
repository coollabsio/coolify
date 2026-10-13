<?php

use App\Models\InstanceSettings;
use App\Models\OauthIdentity;
use App\Models\OauthSetting;
use App\Models\User;
use App\Services\Auth\OauthLoginService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Once;
use Laravel\Fortify\Features;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GitlabProvider;
use SocialiteProviders\Azure\Provider as AzureProvider;
use SocialiteProviders\Discord\Provider as DiscordProvider;
use SocialiteProviders\Google\Provider as GoogleProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
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
        'user' => ['email_verified' => true, 'hd' => 'example.com'],
    ]);

    Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);

    $response = $this->get(route('auth.callback', 'google'));

    $response->assertRedirect('/');
    $this->assertAuthenticatedAs($user);
    expect(User::count())->toBe(1);
    expect(OauthIdentity::where([
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_user_id' => 'google-user-id',
    ])->exists())->toBeTrue();
});

it('never moves an existing oauth identity when the provider email changes', function () {
    config()->set('app.maintenance.driver', 'file');

    $identityOwner = User::factory()->create(['email' => 'old@example.com']);
    $otherUser = User::factory()->create(['email' => 'new@example.com']);
    $identity = OauthIdentity::create([
        'user_id' => $identityOwner->id,
        'provider' => 'google',
        'issuer' => 'google',
        'provider_user_id' => 'google-user-id',
        'email' => 'old@example.com',
    ]);

    $provider = Mockery::mock();
    $provider->shouldReceive('setConfig')->once()->andReturnSelf();
    $provider->shouldReceive('with')->once()->with(['hd' => 'example.com'])->andReturnSelf();
    $provider->shouldReceive('user')->once()->andReturn((object) [
        'email' => 'new@example.com',
        'name' => 'Example User',
        'id' => 'google-user-id',
        'user' => ['email_verified' => true, 'hd' => 'example.com'],
    ]);

    Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);

    $this->get(route('auth.callback', 'google'))->assertRedirect('/');

    $this->assertAuthenticatedAs($identityOwner);
    expect($identity->refresh()->user_id)->toBe($identityOwner->id)
        ->and($identity->email)->toBe('new@example.com')
        ->and($identity->user_id)->not->toBe($otherUser->id);
});

it('keeps an existing oauth identity working without new email verification evidence', function () {
    $user = User::factory()->create(['email' => 'existing@example.com']);
    OauthIdentity::create([
        'user_id' => $user->id,
        'provider' => 'discord',
        'issuer' => 'discord',
        'provider_user_id' => 'existing-discord-id',
        'email' => $user->email,
    ]);
    $discordSetting = OauthSetting::create([
        'provider' => 'discord',
        'client_id' => 'discord-client-id',
        'client_secret' => 'discord-client-secret',
        'enabled' => true,
    ]);

    $resolvedUser = app(OauthLoginService::class)->login('discord', (object) [
        'email' => 'changed@example.com',
        'name' => 'Existing Discord User',
        'id' => 'existing-discord-id',
        'user' => ['verified' => false],
    ], $discordSetting);

    expect($resolvedUser->is($user))->toBeTrue();
    $this->assertAuthenticatedAs($user);
    $this->assertDatabaseHas('oauth_identities', [
        'user_id' => $user->id,
        'provider' => 'discord',
        'provider_user_id' => 'existing-discord-id',
        'email' => 'changed@example.com',
    ]);
});

it('continues oauth login when another request creates the identity first', function () {
    $user = User::factory()->create(['email' => 'race@example.com']);
    $eventName = 'eloquent.creating: '.OauthIdentity::class;

    Event::listen($eventName, function (OauthIdentity $identity): void {
        $attributes = $identity->getAttributes();

        DB::afterRollBack(fn () => DB::table('oauth_identities')->insert($attributes));

        throw new UniqueConstraintViolationException(
            DB::getDefaultConnection(),
            'insert into oauth_identities',
            [],
            new PDOException('duplicate identity'),
        );
    });

    try {
        $resolvedUser = app(OauthLoginService::class)->login('google', (object) [
            'email' => 'race@example.com',
            'name' => 'Race User',
            'id' => 'google-race-id',
            'user' => ['email_verified' => true, 'hd' => 'example.com'],
        ], OauthSetting::where('provider', 'google')->firstOrFail());
    } finally {
        Event::forget($eventName);
    }

    expect($resolvedUser->is($user))->toBeTrue()
        ->and(OauthIdentity::where('provider_user_id', 'google-race-id')->count())->toBe(1);
    $this->assertAuthenticatedAs($user);
});

it('requires strict Discord email verification values', function (mixed $verified) {
    $existingUser = User::factory()->create(['email' => 'existing@example.com']);
    $discordSetting = OauthSetting::create([
        'provider' => 'discord',
        'client_id' => 'discord-client-id',
        'client_secret' => 'discord-client-secret',
        'enabled' => true,
    ]);

    expect(fn () => app(OauthLoginService::class)->login('discord', (object) [
        'email' => 'existing@example.com',
        'name' => 'Discord User',
        'id' => 'discord-user-id',
        'user' => ['verified' => $verified],
    ], $discordSetting))->toThrow(HttpException::class);

    $this->assertGuest();
    expect(OauthIdentity::where('user_id', $existingUser->id)->exists())->toBeFalse();
})->with([
    'unverified' => false,
    'string true' => 'true',
    'integer true' => 1,
]);

it('keeps the Discord verified claim in the raw Socialite user payload', function () {
    $provider = (new ReflectionClass(DiscordProvider::class))->newInstanceWithoutConstructor();
    $mapUser = new ReflectionMethod(DiscordProvider::class, 'mapUserToObject');

    $oauthUser = $mapUser->invoke($provider, [
        'id' => 'discord-user-id',
        'username' => 'Discord User',
        'discriminator' => '0',
        'email' => 'user@example.com',
        'verified' => false,
        'avatar' => null,
    ]);

    expect($oauthUser->user['verified'])->toBeFalse();
});

function mapOauthPayloadWithVendorProvider(string $providerClass, array $payload): object
{
    $provider = (new ReflectionClass($providerClass))->newInstanceWithoutConstructor();

    return (new ReflectionMethod($providerClass, 'mapUserToObject'))->invoke($provider, $payload);
}

it('links an existing account on the first login after upgrade from the real provider payload', function (string $provider, string $providerClass, array $payload) {
    $user = User::factory()->create(['email' => 'existing@example.com']);
    $setting = OauthSetting::updateOrCreate(['provider' => $provider], [
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'enabled' => true,
    ]);

    $resolvedUser = app(OauthLoginService::class)->login(
        $provider,
        mapOauthPayloadWithVendorProvider($providerClass, $payload),
        $setting,
    );

    expect($resolvedUser->is($user))->toBeTrue()
        ->and(OauthIdentity::where(['user_id' => $user->id, 'provider' => $provider])->exists())->toBeTrue();
    $this->assertAuthenticatedAs($user);
})->with([
    'google userinfo v3' => ['google', GoogleProvider::class, [
        'sub' => '110169484474386276334',
        'name' => 'Existing User',
        'picture' => 'https://lh3.googleusercontent.com/a/photo.jpg',
        'email' => 'existing@example.com',
        'email_verified' => true,
        'hd' => 'example.com',
    ]],
    'azure graph me' => ['azure', AzureProvider::class, [
        'id' => '87d349ed-44d7-43e1-9a83-5f2406dee5bd',
        'displayName' => 'Existing User',
        'userPrincipalName' => 'Existing@example.com',
        'mail' => null,
    ]],
    'gitlab user' => ['gitlab', GitlabProvider::class, [
        'id' => 42,
        'username' => 'existing',
        'name' => 'Existing User',
        'email' => 'existing@example.com',
        'avatar_url' => null,
        'confirmed_at' => '2026-01-10T09:05:22Z',
    ]],
]);

function rerunCreatedBeforeOauthIdentitiesMigration(): void
{
    $migration = require database_path('migrations/2026_09_29_200325_add_created_before_oauth_identities_to_users_table.php');
    $migration->down();
    $migration->up();
}

/**
 * Brings the users into the state after an upgrade from v4.3.23.
 */
function upgradeUsersToOauthIdentities(): void
{
    rerunCreatedBeforeOauthIdentitiesMigration();
}

it('marks only password-less users that exist at upgrade time as created before OAuth identities', function () {
    $rootUser = User::factory()->create(['id' => 0]);
    $passwordUser = User::factory()->create();
    $oauthCreatedUser = User::factory()->create(['password' => null]);

    rerunCreatedBeforeOauthIdentitiesMigration();

    $newUser = User::factory()->create(['password' => null]);

    expect($oauthCreatedUser->refresh()->created_before_oauth_identities)->toBeTrue()
        ->and($rootUser->refresh()->created_before_oauth_identities)->toBeFalse()
        ->and($passwordUser->refresh()->created_before_oauth_identities)->toBeFalse()
        ->and($newUser->refresh()->created_before_oauth_identities)->toBeFalse();
});

it('does not link an unverified provider email to a password user after the upgrade', function (string $provider, array $rawClaims) {
    $user = User::factory()->create(['id' => 0, 'email' => 'root@example.com']);
    $setting = OauthSetting::updateOrCreate(['provider' => $provider], [
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'base_url' => 'https://auth.example.com',
        'enabled' => true,
    ]);
    upgradeUsersToOauthIdentities();

    expect(fn () => app(OauthLoginService::class)->login($provider, (object) [
        'email' => 'root@example.com',
        'name' => 'Attacker',
        'id' => 'attacker-provider-id',
        'user' => $rawClaims,
    ], $setting))->toThrow(HttpException::class, 'OAuth provider did not verify the email address');

    $this->assertGuest();
    expect(OauthIdentity::count())->toBe(0)
        ->and($user->refresh()->created_before_oauth_identities)->toBeFalse();
})->with([
    'discord unverified' => ['discord', ['verified' => false]],
    'authentik default email scope' => ['authentik', ['email_verified' => false]],
]);

it('links a verified provider email to a password user after the upgrade', function (string $provider, array $rawClaims) {
    $user = User::factory()->create(['email' => 'member@example.com']);
    $setting = OauthSetting::updateOrCreate(['provider' => $provider], [
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'enabled' => true,
    ]);
    upgradeUsersToOauthIdentities();

    $resolvedUser = app(OauthLoginService::class)->login($provider, (object) [
        'email' => 'member@example.com',
        'name' => 'Member',
        'id' => 'member-provider-id',
        'user' => $rawClaims,
    ], $setting);

    expect($resolvedUser->is($user))->toBeTrue()
        ->and(OauthIdentity::where(['user_id' => $user->id, 'provider' => $provider])->exists())->toBeTrue();
    $this->assertAuthenticatedAs($user);
})->with([
    'github' => ['github', []],
    'google verified' => ['google', ['email_verified' => true, 'hd' => 'example.com']],
    'discord verified' => ['discord', ['verified' => true]],
]);

it('links a password-less user from before the upgrade without an email verification claim', function () {
    $user = User::factory()->create(['email' => 'legacy@example.com', 'password' => null]);
    $setting = OauthSetting::create([
        'provider' => 'discord',
        'client_id' => 'discord-client-id',
        'client_secret' => 'discord-client-secret',
        'enabled' => true,
    ]);
    upgradeUsersToOauthIdentities();

    $resolvedUser = app(OauthLoginService::class)->login('discord', (object) [
        'email' => 'legacy@example.com',
        'name' => 'Legacy User',
        'id' => 'legacy-discord-id',
        'user' => ['verified' => false],
    ], $setting);

    expect($resolvedUser->is($user))->toBeTrue()
        ->and($user->refresh()->created_before_oauth_identities)->toBeFalse();
    $this->assertAuthenticatedAs($user);
});

it('links a user from before the upgrade without an email verification claim', function (string $provider, array $rawClaims, ?string $password) {
    $user = User::factory()->create([
        'email' => 'legacy@example.com',
        'password' => $password,
        'created_before_oauth_identities' => true,
    ]);
    $setting = OauthSetting::updateOrCreate(['provider' => $provider], [
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'base_url' => 'https://auth.example.com',
        'enabled' => true,
    ]);

    $resolvedUser = app(OauthLoginService::class)->login($provider, (object) [
        'email' => 'legacy@example.com',
        'name' => 'Legacy User',
        'id' => 'legacy-provider-id',
        'user' => $rawClaims,
    ], $setting);

    expect($resolvedUser->is($user))->toBeTrue()
        ->and(OauthIdentity::where(['user_id' => $user->id, 'provider' => $provider])->exists())->toBeTrue()
        ->and($user->refresh()->created_before_oauth_identities)->toBeFalse();
    $this->assertAuthenticatedAs($user);
})->with([
    'discord unverified, user created by OAuth' => ['discord', ['verified' => false], null],
    'authentik default email scope, user created by OAuth' => ['authentik', ['email_verified' => false], null],
]);

it('does not link an unverified provider email to a flagged user who has a password', function (string $provider, array $rawClaims) {
    // A pre-upgrade OAuth user who set a password (for example through a password
    // reset) before the first OAuth login, or a password user on an instance that
    // ran the first version of the migration.
    $user = User::factory()->create([
        'email' => 'legacy@example.com',
        'password' => 'password',
        'created_before_oauth_identities' => true,
    ]);
    $setting = OauthSetting::updateOrCreate(['provider' => $provider], [
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'base_url' => 'https://auth.example.com',
        'enabled' => true,
    ]);

    expect(fn () => app(OauthLoginService::class)->login($provider, (object) [
        'email' => 'legacy@example.com',
        'name' => 'Attacker',
        'id' => 'attacker-provider-id',
        'user' => $rawClaims,
    ], $setting))->toThrow(HttpException::class, 'OAuth provider did not verify the email address');

    expect(OauthIdentity::count())->toBe(0);
    $this->assertGuest();
})->with([
    'discord unverified' => ['discord', ['verified' => false]],
    'authentik default email scope' => ['authentik', ['email_verified' => false]],
]);

it('does not link a second provider to a user from before the upgrade', function () {
    $user = User::factory()->create([
        'email' => 'legacy@example.com',
        'password' => null,
        'created_before_oauth_identities' => true,
    ]);
    OauthIdentity::create([
        'user_id' => $user->id,
        'provider' => 'github',
        'issuer' => 'github',
        'provider_user_id' => 'github-legacy-id',
        'email' => $user->email,
    ]);
    $discordSetting = OauthSetting::create([
        'provider' => 'discord',
        'client_id' => 'discord-client-id',
        'client_secret' => 'discord-client-secret',
        'enabled' => true,
    ]);

    expect(fn () => app(OauthLoginService::class)->login('discord', (object) [
        'email' => 'legacy@example.com',
        'name' => 'Attacker',
        'id' => 'discord-attacker-id',
        'user' => ['verified' => false],
    ], $discordSetting))->toThrow(HttpException::class, 'OAuth identity cannot be linked to this account');

    $this->assertGuest();
    expect(OauthIdentity::count())->toBe(1);
});

it('does not link an existing account when the provider payload does not verify the email', function (string $provider, string $providerClass, array $payload, ?string $unverifiedEmail = null) {
    User::factory()->create(['email' => 'existing@example.com']);
    $setting = OauthSetting::updateOrCreate(['provider' => $provider], [
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'enabled' => true,
    ]);
    $oauthUser = mapOauthPayloadWithVendorProvider($providerClass, $payload);
    if ($unverifiedEmail !== null) {
        $oauthUser->email = $unverifiedEmail;
    }

    expect(fn () => app(OauthLoginService::class)->login($provider, $oauthUser, $setting))
        ->toThrow(HttpException::class, 'OAuth provider did not verify the email address');

    $this->assertGuest();
    expect(OauthIdentity::count())->toBe(0);
})->with([
    'google unverified' => ['google', GoogleProvider::class, [
        'sub' => '110169484474386276334',
        'name' => 'Existing User',
        'picture' => null,
        'email' => 'existing@example.com',
        'email_verified' => false,
        'hd' => 'example.com',
    ]],
    'azure mail attribute instead of the user principal name' => ['azure', AzureProvider::class, [
        'id' => '87d349ed-44d7-43e1-9a83-5f2406dee5bd',
        'displayName' => 'Existing User',
        'userPrincipalName' => 'someone@other-tenant.onmicrosoft.com',
        'mail' => 'existing@example.com',
    ], 'existing@example.com'],
    'gitlab unconfirmed' => ['gitlab', GitlabProvider::class, [
        'id' => 42,
        'username' => 'existing',
        'name' => 'Existing User',
        'email' => 'existing@example.com',
        'avatar_url' => null,
        'confirmed_at' => null,
    ]],
]);

it('registers a new user from a verified provider identity', function () {
    InstanceSettings::findOrFail(0)->update(['is_registration_enabled' => true]);

    $user = app(OauthLoginService::class)->login('google', (object) [
        'email' => 'verified@example.com',
        'name' => 'Verified User',
        'id' => 'verified-google-id',
        'user' => ['email_verified' => true, 'hd' => 'example.com'],
    ], OauthSetting::where('provider', 'google')->firstOrFail());

    expect($user->email)->toBe('verified@example.com');
    expect($user->email_verified_at)->toBeNull();
    $this->assertAuthenticatedAs($user);
    $this->assertDatabaseHas('oauth_identities', [
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_user_id' => 'verified-google-id',
    ]);
});

it('does not register a new user through a non-OIDC provider when registration is disabled', function (string $provider, array $rawClaims) {
    // Upgraded installs have allow_registration = true on every provider row (column default).
    OauthSetting::updateOrCreate(['provider' => $provider], [
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'enabled' => true,
        'allow_registration' => true,
    ]);

    expect(fn () => app(OauthLoginService::class)->login($provider, (object) [
        'email' => 'stranger@example.com',
        'name' => 'Stranger',
        'id' => 'stranger-id',
        'user' => $rawClaims,
    ], OauthSetting::where('provider', $provider)->firstOrFail()))->toThrow(HttpException::class, 'Registration is disabled');

    $this->assertGuest();
    expect(User::count())->toBe(0)
        ->and(OauthIdentity::count())->toBe(0);
})->with([
    'github' => ['github', []],
    'google' => ['google', ['email_verified' => true, 'hd' => 'example.com']],
]);

it('registers a new user through a non-OIDC provider when registration is enabled', function () {
    InstanceSettings::findOrFail(0)->update(['is_registration_enabled' => true]);
    OauthSetting::create([
        'provider' => 'github',
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'enabled' => true,
        'allow_registration' => false,
    ]);

    $user = app(OauthLoginService::class)->login('github', (object) [
        'email' => 'new-user@example.com',
        'name' => 'New User',
        'id' => 'github-new-user-id',
        'user' => [],
    ], OauthSetting::where('provider', 'github')->firstOrFail());

    expect($user->email)->toBe('new-user@example.com');
    $this->assertAuthenticatedAs($user);
});

it('does not link another provider identity to an account by shared email', function () {
    $user = User::factory()->create(['email' => 'shared@example.com']);
    OauthIdentity::create([
        'user_id' => $user->id,
        'provider' => 'github',
        'issuer' => 'github',
        'provider_user_id' => 'github-user-id',
        'email' => 'shared@example.com',
    ]);

    expect(fn () => app(OauthLoginService::class)->login('google', (object) [
        'email' => 'shared@example.com',
        'name' => 'Other Provider User',
        'id' => 'google-user-id',
        'user' => ['email_verified' => true, 'hd' => 'example.com'],
    ], OauthSetting::where('provider', 'google')->firstOrFail()))->toThrow(HttpException::class);

    $this->assertGuest();
    expect(OauthIdentity::count())->toBe(1);
});

it('sends an OAuth user with confirmed two factor authentication to the Fortify challenge', function () {
    config()->set('fortify.features', [Features::twoFactorAuthentication(['confirm' => true])]);
    $user = User::factory()->create([
        'email' => 'two-factor@example.com',
        'two_factor_secret' => encrypt('secret'),
        'two_factor_confirmed_at' => now(),
    ]);
    OauthIdentity::create([
        'user_id' => $user->id,
        'provider' => 'google',
        'issuer' => 'google',
        'provider_user_id' => 'two-factor-google-id',
        'email' => $user->email,
    ]);

    $provider = Mockery::mock();
    $provider->shouldReceive('setConfig')->once()->andReturnSelf();
    $provider->shouldReceive('with')->once()->andReturnSelf();
    $provider->shouldReceive('user')->once()->andReturn((object) [
        'email' => $user->email,
        'name' => $user->name,
        'id' => 'two-factor-google-id',
        'user' => ['email_verified' => true, 'hd' => 'example.com'],
    ]);
    Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);

    $this->get(route('auth.callback', 'google'))
        ->assertRedirect(route('two-factor.login'))
        ->assertSessionHas('login.id', $user->id);

    $this->assertGuest();
});

it('completes OAuth login without a challenge when two factor authentication is not enabled for the user', function () {
    $user = User::factory()->create(['email' => 'without-two-factor@example.com']);
    OauthIdentity::create([
        'user_id' => $user->id,
        'provider' => 'google',
        'issuer' => 'google',
        'provider_user_id' => 'plain-google-id',
        'email' => $user->email,
    ]);

    $provider = Mockery::mock();
    $provider->shouldReceive('setConfig')->once()->andReturnSelf();
    $provider->shouldReceive('with')->once()->andReturnSelf();
    $provider->shouldReceive('user')->once()->andReturn((object) [
        'email' => $user->email,
        'name' => $user->name,
        'id' => 'plain-google-id',
        'user' => ['email_verified' => true, 'hd' => 'example.com'],
    ]);
    Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);

    $this->get(route('auth.callback', 'google'))->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
});

it('rejects redirect requests for a disabled provider', function () {
    $this->withoutVite();
    OauthSetting::where('provider', 'google')->update(['enabled' => false]);

    $this->get(route('auth.redirect', 'google'))->assertForbidden();
    $this->assertGuest();
});

it('rejects callback requests for a disabled provider', function () {
    OauthSetting::where('provider', 'google')->update(['enabled' => false]);

    $this->from('/login')->get(route('auth.callback', 'google'))->assertRedirect('/login');
    $this->assertGuest();
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
    'malformed email' => ['not-an-email'],
    'missing domain' => ['user@'],
]);

it('rejects oauth logins when the provider does not return a valid user id', function (mixed $invalidId) {
    $oauthUser = (object) [
        'email' => 'user@example.edu',
        'name' => 'Example User',
    ];

    if ($invalidId !== 'missing') {
        $oauthUser->id = $invalidId;
    }

    try {
        app(OauthLoginService::class)->login('google', $oauthUser, OauthSetting::where('provider', 'google')->firstOrFail());
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(403)
            ->and(OauthIdentity::count())->toBe(0)
            ->and(User::count())->toBe(0);

        return;
    }

    $this->fail('Expected an invalid OAuth provider user ID to be rejected.');
})->with([
    'null id' => [null],
    'missing id' => ['missing'],
    'blank id' => ['   '],
    'non-scalar id' => [[]],
    'true id' => [true],
    'false id' => [false],
    'float id' => [1.0],
]);

it('rejects a Google account outside the configured Workspace even when its email is verified', function () {
    $user = User::factory()->create(['email' => 'user@outside.example']);
    $setting = OauthSetting::where('provider', 'google')->firstOrFail();

    expect(fn () => app(OauthLoginService::class)->login('google', (object) [
        'email' => $user->email,
        'name' => 'Outside User',
        'id' => 'google-outside-id',
        'user' => ['email_verified' => true, 'hd' => 'outside.example'],
    ], $setting))->toThrow(HttpException::class);

    expect(OauthIdentity::count())->toBe(0);
    $this->assertGuest();
});

it('sends the instance callback url without saving it when a forged host starts an oauth login', function () {
    InstanceSettings::query()->whereKey(0)->update(['fqdn' => 'https://coolify.example.com']);
    Once::flush();
    OauthSetting::create([
        'provider' => 'github',
        'client_id' => 'github-client-id',
        'client_secret' => 'github-client-secret',
        'enabled' => true,
    ]);

    $response = $this->get('http://attacker.example/auth/github/redirect');

    $response->assertRedirect();
    parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
    expect($query['redirect_uri'])->toBe('https://coolify.example.com/auth/github/callback')
        ->and(OauthSetting::where('provider', 'github')->value('redirect_uri'))->toBeNull();
});

function mockGoogleCallbackUser(array $oauthUser): void
{
    $provider = Mockery::mock();
    $provider->shouldReceive('setConfig')->andReturnSelf();
    $provider->shouldReceive('with')->andReturnSelf();
    $provider->shouldReceive('user')->andReturn((object) $oauthUser);
    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
}

describe('callback error messages', function () {
    beforeEach(function () {
        config()->set('app.maintenance.driver', 'file');
    });

    it('tells a user that the account already uses another sign-in method', function () {
        $user = User::factory()->create(['email' => 'linked@example.com']);
        OauthIdentity::create([
            'user_id' => $user->id,
            'provider' => 'github',
            'issuer' => 'github',
            'provider_user_id' => 'github-user-id',
            'email' => 'linked@example.com',
        ]);
        mockGoogleCallbackUser([
            'email' => 'linked@example.com',
            'name' => 'Linked User',
            'id' => 'google-user-id',
            'user' => ['email_verified' => true, 'hd' => 'example.com'],
        ]);

        $this->from('/login')->get(route('auth.callback', 'google'))
            ->assertRedirect('/login');
        expect(session('errors')->first())->toBe(__('auth.failed.oauth_already_linked'));
        $this->assertGuest();
    });

    it('tells a user that the provider did not verify the email before it reveals a linked account', function () {
        $user = User::factory()->create(['email' => 'linked@example.com']);
        OauthIdentity::create([
            'user_id' => $user->id,
            'provider' => 'github',
            'issuer' => 'github',
            'provider_user_id' => 'github-user-id',
            'email' => 'linked@example.com',
        ]);
        mockGoogleCallbackUser([
            'email' => 'linked@example.com',
            'name' => 'Unverified User',
            'id' => 'google-user-id',
            'user' => ['email_verified' => false, 'hd' => 'example.com'],
        ]);

        $this->from('/login')->get(route('auth.callback', 'google'))
            ->assertRedirect('/login');
        expect(session('errors')->first())->toBe(__('auth.failed.oauth_email_unverified'));
        $this->assertGuest();
    });

    it('tells a new user that registration is disabled', function () {
        mockGoogleCallbackUser([
            'email' => 'new@example.com',
            'name' => 'New User',
            'id' => 'google-user-id',
            'user' => ['email_verified' => true, 'hd' => 'example.com'],
        ]);

        $this->from('/login')->get(route('auth.callback', 'google'))
            ->assertRedirect('/login');
        expect(session('errors')->first())->toBe(__('auth.registration_disabled'));
        $this->assertGuest();
    });

    it('shows a generic OAuth message instead of the password message for other denied logins', function () {
        mockGoogleCallbackUser([
            'email' => 'outside@example.org',
            'name' => 'Outside User',
            'id' => 'google-user-id',
            'user' => ['email_verified' => true, 'hd' => 'example.org'],
        ]);

        $this->from('/login')->get(route('auth.callback', 'google'))
            ->assertRedirect('/login');
        expect(session('errors')->first())->toBe(__('auth.failed.oauth'))
            ->not->toBe(__('auth.failed'));
        $this->assertGuest();
    });
});

it('matches the Google hosted domain without case or spaces and accepts any Workspace for a wildcard', function (string $tenant, ?string $hostedDomain, bool $allowed) {
    $user = User::factory()->create(['email' => 'user@example.com']);
    $setting = OauthSetting::where('provider', 'google')->firstOrFail();
    $setting->update(['tenant' => $tenant]);
    $claims = array_filter(['email_verified' => true, 'hd' => $hostedDomain], fn ($value) => $value !== null);

    $login = fn () => app(OauthLoginService::class)->login('google', (object) [
        'email' => $user->email,
        'name' => 'Workspace User',
        'id' => 'google-workspace-id',
        'user' => $claims,
    ], $setting);

    if ($allowed) {
        expect($login()->is($user))->toBeTrue();
    } else {
        expect($login)->toThrow(HttpException::class, 'Google account is not in the configured Workspace');
        $this->assertGuest();
    }
})->with([
    'mixed-case tenant' => ['Example.com', 'example.com', true],
    'tenant with spaces' => [' example.com ', 'example.com', true],
    'mixed-case hd claim' => ['example.com', 'EXAMPLE.com', true],
    'wildcard with a Workspace account' => ['*', 'any-company.example', true],
    'wildcard with a personal account' => ['*', null, false],
    'other domain' => ['example.com', 'example.org', false],
    'personal account' => ['example.com', null, false],
]);

it('allows OAuth user creation through global registration or the OIDC user creation setting', function (string $provider, bool $isRegistrationEnabled, bool $allowRegistration, bool $expected) {
    InstanceSettings::query()->whereKey(0)->update(['is_registration_enabled' => $isRegistrationEnabled]);
    Once::flush();

    $setting = new OauthSetting(['provider' => $provider, 'allow_registration' => $allowRegistration]);

    expect($setting->allowsUserCreation())->toBe($expected);
})->with([
    'github, registration on' => ['github', true, false, true],
    'github, registration off' => ['github', false, true, false],
    'oidc, registration off, oidc user creation on' => ['oidc', false, true, true],
    'oidc, registration off, oidc user creation off' => ['oidc', false, false, false],
    'oidc, registration on, oidc user creation off' => ['oidc', true, false, true],
]);

it('registers a new user from an unverified provider email when registration is enabled', function (string $provider, array $rawClaims) {
    InstanceSettings::findOrFail(0)->update(['is_registration_enabled' => true]);
    $setting = OauthSetting::updateOrCreate(['provider' => $provider], [
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'base_url' => 'https://auth.example.com',
        'enabled' => true,
    ]);

    $user = app(OauthLoginService::class)->login($provider, (object) [
        'email' => 'new-user@example.com',
        'name' => 'New User',
        'id' => 'new-provider-id',
        'user' => $rawClaims,
    ], $setting);

    expect($user->email)->toBe('new-user@example.com')
        ->and(OauthIdentity::where(['user_id' => $user->id, 'provider' => $provider])->exists())->toBeTrue();
    $this->assertAuthenticatedAs($user);
})->with([
    'authentik default email scope' => ['authentik', ['email_verified' => false]],
    'discord unverified' => ['discord', ['verified' => false]],
    'gitlab unconfirmed' => ['gitlab', ['confirmed_at' => null]],
]);

it('tells a new user with an unverified provider email that registration is disabled', function () {
    $setting = OauthSetting::updateOrCreate(['provider' => 'authentik'], [
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'base_url' => 'https://auth.example.com',
        'enabled' => true,
    ]);

    expect(fn () => app(OauthLoginService::class)->login('authentik', (object) [
        'email' => 'new-user@example.com',
        'name' => 'New User',
        'id' => 'new-provider-id',
        'user' => ['email_verified' => false],
    ], $setting))->toThrow(HttpException::class, 'Registration is disabled');

    expect(User::count())->toBe(0);
    $this->assertGuest();
});
