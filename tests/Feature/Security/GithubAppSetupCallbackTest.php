<?php

use App\Models\GithubApp;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Team;
use App\Models\User;
use App\Rules\SafeExternalUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::macro('GitSource', fn (string $url) => Http::withOptions(SafeExternalUrl::httpClientOptions(
        $url,
        resolver: fn (string $host): array => ['93.184.216.34'],
    )));

    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->githubApp = GithubApp::create([
        'name' => 'Test GitHub App',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'custom_user' => 'git',
        'custom_port' => 22,
        'team_id' => $this->team->id,
        'is_system_wide' => false,
    ]);
});

function cacheGithubAppSetupState(string $state, string $action, GithubApp $githubApp): void
{
    Cache::put('github-app-setup-state:'.hash('sha256', $state), [
        'action' => $action,
        'github_app_id' => $githubApp->id,
        'team_id' => $githubApp->team_id,
    ], now()->addMinutes(15));
}

function authenticateGithubSetupCallbackTest(object $test): void
{
    $test->actingAs($test->user);
    session(['currentTeam' => $test->team]);
}

function fakeGithubManifestConversion(string $apiUrl = 'https://api.github.com'): void
{
    $key = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    openssl_pkey_export($key, $privateKey);

    Http::preventStrayRequests();
    Http::fake([
        "{$apiUrl}/app-manifests/*/conversions" => Http::response([
            'id' => 987654,
            'slug' => 'attacker-controlled-app',
            'client_id' => 'new-client-id',
            'client_secret' => 'new-client-secret',
            'pem' => $privateKey,
            'webhook_secret' => 'new-webhook-secret',
        ]),
    ]);
}

function configureGithubAppCredentials(GithubApp $githubApp): void
{
    $key = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    openssl_pkey_export($key, $privateKey);

    $privateKeyModel = PrivateKey::create([
        'name' => 'github-app-test-key',
        'private_key' => $privateKey,
        'team_id' => $githubApp->team_id,
        'is_git_related' => true,
    ]);

    $githubApp->forceFill([
        'app_id' => 123456,
        'private_key_id' => $privateKeyModel->id,
    ])->save();
}

function fakeGithubInstallationVerification(int $appId, string $apiUrl = 'https://api.github.com'): void
{
    Http::preventStrayRequests();
    Http::fake([
        "{$apiUrl}/app/installations/*" => Http::response([
            'id' => 555,
            'app_id' => $appId,
        ], 200),
    ]);
}

function fakeGithubInstallationVerificationFailure(): void
{
    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/app/installations/*' => Http::response(['message' => 'Not Found'], 404),
    ]);
}

it('requires authentication before processing github app manifest callbacks', function () {
    fakeGithubManifestConversion();
    cacheGithubAppSetupState('valid-state', 'manifest', $this->githubApp);

    $this->get('/webhooks/source/github/redirect?state=valid-state&code=attacker-code')
        ->assertRedirect();

    Http::assertNothingSent();

    $this->githubApp->refresh();
    expect($this->githubApp->app_id)->toBeNull()
        ->and($this->githubApp->client_id)->toBeNull()
        ->and($this->githubApp->webhook_secret)->toBeNull();
});

it('rejects github app manifest callbacks with invalid state without calling github', function () {
    authenticateGithubSetupCallbackTest($this);
    fakeGithubManifestConversion();

    $this->withHeader('Accept', 'application/json')->get('/webhooks/source/github/redirect?state='.$this->githubApp->uuid.'&code=attacker-code')
        ->assertNotFound();

    Http::assertNothingSent();

    $this->githubApp->refresh();
    expect($this->githubApp->app_id)->toBeNull()
        ->and($this->githubApp->client_id)->toBeNull()
        ->and($this->githubApp->webhook_secret)->toBeNull();
});

it('blocks rebinding an already configured github app through manifest callback', function () {
    authenticateGithubSetupCallbackTest($this);
    fakeGithubManifestConversion();

    $this->githubApp->forceFill([
        'app_id' => 123456,
        'client_id' => 'existing-client-id',
        'client_secret' => 'existing-client-secret',
        'webhook_secret' => 'existing-webhook-secret',
    ])->save();

    cacheGithubAppSetupState('valid-state', 'manifest', $this->githubApp);

    $this->withHeader('Accept', 'application/json')->get('/webhooks/source/github/redirect?state=valid-state&code=attacker-code')
        ->assertForbidden();

    Http::assertNothingSent();

    $this->githubApp->refresh();
    expect($this->githubApp->app_id)->toBe(123456)
        ->and($this->githubApp->client_id)->toBe('existing-client-id')
        ->and($this->githubApp->webhook_secret)->toBe('existing-webhook-secret');
});

it('configures an unbound github app with a valid one-time manifest state', function () {
    authenticateGithubSetupCallbackTest($this);
    fakeGithubManifestConversion();
    cacheGithubAppSetupState('valid-state', 'manifest', $this->githubApp);

    $this->get('/webhooks/source/github/redirect?state=valid-state&code=real-code')
        ->assertRedirect(route('source.github.show', ['github_app_uuid' => $this->githubApp->uuid]));

    Http::assertSentCount(1);

    $this->githubApp->refresh();
    expect($this->githubApp->name)->toBe('attacker-controlled-app')
        ->and($this->githubApp->app_id)->toBe(987654)
        ->and($this->githubApp->client_id)->toBe('new-client-id')
        ->and($this->githubApp->webhook_secret)->toBe('new-webhook-secret')
        ->and($this->githubApp->private_key_id)->not->toBeNull();
});

it('converts ghe dot com app manifests through the data residency api host', function () {
    authenticateGithubSetupCallbackTest($this);
    $this->githubApp->forceFill([
        'api_url' => 'https://api.octocorp.ghe.com',
        'html_url' => 'https://octocorp.ghe.com',
    ])->save();
    fakeGithubManifestConversion('https://api.octocorp.ghe.com');
    cacheGithubAppSetupState('valid-state', 'manifest', $this->githubApp);

    $this->get('/webhooks/source/github/redirect?state=valid-state&code=real-code')
        ->assertRedirect(route('source.github.show', ['github_app_uuid' => $this->githubApp->uuid]));

    Http::assertSent(fn ($request) => $request->url() === 'https://api.octocorp.ghe.com/app-manifests/real-code/conversions');
});

it('rejects replayed github app manifest states', function () {
    authenticateGithubSetupCallbackTest($this);
    fakeGithubManifestConversion();
    cacheGithubAppSetupState('valid-state', 'manifest', $this->githubApp);

    $this->get('/webhooks/source/github/redirect?state=valid-state&code=real-code')
        ->assertRedirect();

    $this->withHeader('Accept', 'application/json')->get('/webhooks/source/github/redirect?state=valid-state&code=real-code')
        ->assertNotFound();

    Http::assertSentCount(1);
});

it('requires authentication before processing github app install callbacks', function () {
    Http::preventStrayRequests();
    cacheGithubAppSetupState('valid-install-state', 'install', $this->githubApp);

    $this->get('/webhooks/source/github/install?state=valid-install-state&setup_action=install&installation_id=123456')
        ->assertRedirect();

    Http::assertNothingSent();

    $this->githubApp->refresh();
    expect($this->githubApp->installation_id)->toBeNull();
});

it('rejects github app install callbacks with an app uuid as state', function () {
    authenticateGithubSetupCallbackTest($this);
    Http::preventStrayRequests();

    $this->withHeader('Accept', 'application/json')->get('/webhooks/source/github/install?state='.$this->githubApp->uuid.'&setup_action=install&installation_id=123456')
        ->assertNotFound();

    Http::assertNothingSent();
});

it('redirects browser github app install callbacks with missing or expired state to sources', function () {
    authenticateGithubSetupCallbackTest($this);
    Http::preventStrayRequests();

    $this->get('/webhooks/source/github/install?setup_action=install&installation_id=123456')
        ->assertRedirect(route('source.all'));

    $this->get('/webhooks/source/github/install?state=expired-state&setup_action=install&installation_id=123456')
        ->assertRedirect(route('source.all'));

    Http::assertNothingSent();
});

it('rejects github app setup states for the wrong callback action', function () {
    authenticateGithubSetupCallbackTest($this);
    Http::preventStrayRequests();
    cacheGithubAppSetupState('manifest-state', 'manifest', $this->githubApp);
    cacheGithubAppSetupState('install-state', 'install', $this->githubApp);

    $this->withHeader('Accept', 'application/json')->get('/webhooks/source/github/install?state=manifest-state&setup_action=install&installation_id=123456')
        ->assertNotFound();

    $this->withHeader('Accept', 'application/json')->get('/webhooks/source/github/redirect?state=install-state&code=real-code')
        ->assertNotFound();

    Http::assertNothingSent();
});

it('allows github app install callbacks for repository update setup actions', function () {
    authenticateGithubSetupCallbackTest($this);
    configureGithubAppCredentials($this->githubApp);
    $this->githubApp->forceFill(['installation_id' => 111111])->save();
    Http::preventStrayRequests();

    $this->get('/webhooks/source/github/install?setup_action=update&installation_id=111111')
        ->assertRedirect(route('source.github.show', ['github_app_uuid' => $this->githubApp->uuid]));

    Http::assertNothingSent();

    $this->githubApp->refresh();
    expect($this->githubApp->installation_id)->toBe(111111);
});

it('redirects github app repository update callbacks without a matching source to the sources page', function () {
    authenticateGithubSetupCallbackTest($this);
    Http::preventStrayRequests();

    $this->get('/webhooks/source/github/install?setup_action=update&installation_id=123456')
        ->assertRedirect(route('source.all'));

    Http::assertNothingSent();
});

it('rejects github app install callbacks for unknown setup actions', function () {
    authenticateGithubSetupCallbackTest($this);
    Http::preventStrayRequests();
    cacheGithubAppSetupState('valid-install-state', 'install', $this->githubApp);

    $this->withHeader('Accept', 'application/json')->get('/webhooks/source/github/install?state=valid-install-state&setup_action=remove&installation_id=123456')
        ->assertUnprocessable();

    Http::assertNothingSent();
});

it('rejects github app setup states from another team', function () {
    authenticateGithubSetupCallbackTest($this);
    Http::preventStrayRequests();

    $otherTeam = Team::factory()->create();
    $otherGithubApp = GithubApp::create([
        'name' => 'Other GitHub App',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'custom_user' => 'git',
        'custom_port' => 22,
        'team_id' => $otherTeam->id,
        'is_system_wide' => false,
    ]);

    cacheGithubAppSetupState('other-team-state', 'manifest', $otherGithubApp);

    $this->withHeader('Accept', 'application/json')->get('/webhooks/source/github/redirect?state=other-team-state&code=real-code')
        ->assertForbidden();

    Http::assertNothingSent();
});

it('rejects an installation id that github does not confirm belongs to the app', function () {
    authenticateGithubSetupCallbackTest($this);
    configureGithubAppCredentials($this->githubApp);
    fakeGithubInstallationVerificationFailure();
    cacheGithubAppSetupState('valid-install-state', 'install', $this->githubApp);

    $this->withHeader('Accept', 'application/json')->get('/webhooks/source/github/install?state=valid-install-state&setup_action=install&installation_id=999999')
        ->assertForbidden();

    $this->githubApp->refresh();
    expect($this->githubApp->installation_id)->toBeNull();
});

it('sets installation id when github confirms it belongs to the app', function () {
    authenticateGithubSetupCallbackTest($this);
    configureGithubAppCredentials($this->githubApp);
    fakeGithubInstallationVerification($this->githubApp->app_id);
    cacheGithubAppSetupState('valid-install-state', 'install', $this->githubApp);

    $this->get('/webhooks/source/github/install?state=valid-install-state&setup_action=install&installation_id=123456')
        ->assertRedirect(route('source.github.show', ['github_app_uuid' => $this->githubApp->uuid]));

    $this->githubApp->refresh();
    expect($this->githubApp->installation_id)->toBe(123456);
});

it('verifies ghe dot com installations through the data residency api host', function () {
    authenticateGithubSetupCallbackTest($this);
    $this->githubApp->forceFill([
        'api_url' => 'https://api.octocorp.ghe.com',
        'html_url' => 'https://octocorp.ghe.com',
    ])->save();
    configureGithubAppCredentials($this->githubApp);
    fakeGithubInstallationVerification($this->githubApp->app_id, 'https://api.octocorp.ghe.com');
    cacheGithubAppSetupState('valid-install-state', 'install', $this->githubApp);

    $this->get('/webhooks/source/github/install?state=valid-install-state&setup_action=install&installation_id=123456')
        ->assertRedirect(route('source.github.show', ['github_app_uuid' => $this->githubApp->uuid]));

    Http::assertSent(fn ($request) => $request->url() === 'https://api.octocorp.ghe.com/app/installations/123456');
});

it('rejects replayed github app install states', function () {
    authenticateGithubSetupCallbackTest($this);
    configureGithubAppCredentials($this->githubApp);
    fakeGithubInstallationVerification($this->githubApp->app_id);
    cacheGithubAppSetupState('valid-install-state', 'install', $this->githubApp);

    $this->get('/webhooks/source/github/install?state=valid-install-state&setup_action=install&installation_id=123456')
        ->assertRedirect();

    $this->withHeader('Accept', 'application/json')->get('/webhooks/source/github/install?state=valid-install-state&setup_action=install&installation_id=123456')
        ->assertNotFound();

    $this->githubApp->refresh();
    expect($this->githubApp->installation_id)->toBe(123456);
});

it('allows reinstalling an already configured github app installation id', function () {
    authenticateGithubSetupCallbackTest($this);
    configureGithubAppCredentials($this->githubApp);
    $this->githubApp->forceFill(['installation_id' => 111111])->save();
    fakeGithubInstallations($this->githubApp->app_id, [
        111111 => ['id' => 1, 'login' => 'acme-org'],
        222222 => ['id' => 1, 'login' => 'acme-org'],
    ]);
    cacheGithubAppSetupState('valid-install-state', 'install', $this->githubApp);

    $this->get('/webhooks/source/github/install?state=valid-install-state&setup_action=install&installation_id=222222')
        ->assertRedirect(route('source.github.show', ['github_app_uuid' => $this->githubApp->uuid]));

    $this->githubApp->refresh();
    expect($this->githubApp->installation_id)->toBe(222222)
        ->and(GithubApp::count())->toBe(1);
});

/**
 * @param  array<int|string, array{id: int, login: string}|null>  $installations  Installation ID => account, or null when GitHub no longer knows the installation.
 */
function fakeGithubInstallations(int $appId, array $installations): void
{
    Http::preventStrayRequests();
    Http::fake(collect($installations)->mapWithKeys(fn (?array $account, int|string $installationId) => [
        "https://api.github.com/app/installations/{$installationId}" => $account === null
            ? Http::response(['message' => 'Not Found'], 404)
            : Http::response([
                'id' => (int) $installationId,
                'app_id' => $appId,
                'account' => $account,
                'permissions' => ['contents' => 'read', 'deployments' => 'write'],
            ], 200),
    ])->all());
}

it('adds a source for an installation on another account and keeps the current installation', function () {
    authenticateGithubSetupCallbackTest($this);
    configureGithubAppCredentials($this->githubApp);
    $this->githubApp->forceFill([
        'installation_id' => 111111,
        'installation_account' => 'personal-user',
        'organization' => 'app-owner',
        'runner_group_id' => 7,
        'client_secret' => 'client-secret',
        'webhook_secret' => 'webhook-secret',
    ])->save();
    fakeGithubInstallations($this->githubApp->app_id, [
        111111 => ['id' => 1, 'login' => 'personal-user'],
        222222 => ['id' => 2, 'login' => 'acme-org'],
    ]);
    cacheGithubAppSetupState('valid-install-state', 'install', $this->githubApp);

    $response = $this->get('/webhooks/source/github/install?state=valid-install-state&setup_action=install&installation_id=222222');

    $newSource = GithubApp::where('installation_id', 222222)->sole();
    $response->assertRedirect(route('source.github.show', ['github_app_uuid' => $newSource->uuid]))
        ->assertSessionHas('success');

    expect($this->githubApp->refresh()->installation_id)->toBe(111111)
        ->and($newSource->uuid)->not->toBe($this->githubApp->uuid)
        ->and($newSource->only(['team_id', 'name', 'organization', 'api_url', 'html_url', 'app_id', 'private_key_id', 'client_secret', 'webhook_secret', 'is_system_wide']))
        ->toBe($this->githubApp->only(['team_id', 'name', 'organization', 'api_url', 'html_url', 'app_id', 'private_key_id', 'client_secret', 'webhook_secret', 'is_system_wide']))
        ->and($newSource->installation_account)->toBe('acme-org')
        ->and($newSource->accountName())->toBe('acme-org')
        ->and($newSource->deployments)->toBe('write')
        ->and($newSource->runner_group_id)->toBeNull();
});

it('reuses the source of an installation that was added before', function () {
    authenticateGithubSetupCallbackTest($this);
    configureGithubAppCredentials($this->githubApp);
    $this->githubApp->forceFill(['installation_id' => 111111])->save();
    $existing = $this->githubApp->replicate(['uuid']);
    $existing->installation_id = 222222;
    $existing->save();
    fakeGithubInstallations($this->githubApp->app_id, [
        111111 => ['id' => 1, 'login' => 'personal-user'],
        222222 => ['id' => 2, 'login' => 'acme-org'],
    ]);
    cacheGithubAppSetupState('valid-install-state', 'install', $this->githubApp);

    $this->get('/webhooks/source/github/install?state=valid-install-state&setup_action=install&installation_id=222222')
        ->assertRedirect(route('source.github.show', ['github_app_uuid' => $existing->uuid]));

    expect(GithubApp::where('app_id', $this->githubApp->app_id)->count())->toBe(2)
        ->and($existing->refresh()->installation_account)->toBe('acme-org')
        ->and($this->githubApp->refresh()->installation_id)->toBe(111111);
});

it('replaces an installation that was removed at github', function () {
    authenticateGithubSetupCallbackTest($this);
    configureGithubAppCredentials($this->githubApp);
    $this->githubApp->forceFill(['installation_id' => 111111])->save();
    fakeGithubInstallations($this->githubApp->app_id, [
        111111 => null,
        222222 => ['id' => 2, 'login' => 'acme-org'],
    ]);
    cacheGithubAppSetupState('valid-install-state', 'install', $this->githubApp);

    $this->get('/webhooks/source/github/install?state=valid-install-state&setup_action=install&installation_id=222222')
        ->assertRedirect(route('source.github.show', ['github_app_uuid' => $this->githubApp->uuid]));

    expect($this->githubApp->refresh()->installation_id)->toBe(222222)
        ->and($this->githubApp->installation_account)->toBe('acme-org')
        ->and(GithubApp::count())->toBe(1);
});

it('redirects to the github app with the reason when github does not confirm the installation', function () {
    authenticateGithubSetupCallbackTest($this);
    configureGithubAppCredentials($this->githubApp);
    fakeGithubInstallationVerificationFailure();
    cacheGithubAppSetupState('valid-install-state', 'install', $this->githubApp);

    $this->get('/webhooks/source/github/install?state=valid-install-state&setup_action=install&installation_id=999999')
        ->assertRedirect(route('source.github.show', ['github_app_uuid' => $this->githubApp->uuid]))
        ->assertSessionHas('error', fn (string $error) => str_contains($error, 'HTTP 404: Not Found'));

    $this->githubApp->refresh();
    expect($this->githubApp->installation_id)->toBeNull();
});

it('redirects to the github app with the reason when the server clock is out of sync with github', function () {
    authenticateGithubSetupCallbackTest($this);
    configureGithubAppCredentials($this->githubApp);
    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/app/installations/*' => Http::response(
            ['message' => "'Issued at' claim ('iat') must be an Integer representing a time in the past"],
            401,
            ['Date' => now()->addMinutes(5)->toRfc7231String()],
        ),
    ]);
    cacheGithubAppSetupState('valid-install-state', 'install', $this->githubApp);

    $this->get('/webhooks/source/github/install?state=valid-install-state&setup_action=install&installation_id=123456')
        ->assertRedirect(route('source.github.show', ['github_app_uuid' => $this->githubApp->uuid]))
        ->assertSessionHas('error', fn (string $error) => str_contains($error, 'System time is out of sync with GitHub API time'));

    $this->githubApp->refresh();
    expect($this->githubApp->installation_id)->toBeNull();
});

it('completes the github app installation after the user switched to another of their teams', function () {
    authenticateGithubSetupCallbackTest($this);
    configureGithubAppCredentials($this->githubApp);
    fakeGithubInstallationVerification($this->githubApp->app_id);
    cacheGithubAppSetupState('valid-install-state', 'install', $this->githubApp);

    $otherTeam = Team::factory()->create();
    $otherTeam->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $otherTeam]);

    $this->get('/webhooks/source/github/install?state=valid-install-state&setup_action=install&installation_id=123456')
        ->assertRedirect(route('source.github.show', ['github_app_uuid' => $this->githubApp->uuid]));

    $this->githubApp->refresh();
    expect($this->githubApp->installation_id)->toBe(123456)
        ->and(data_get(session('currentTeam'), 'id'))->toBe($this->team->id);
});
