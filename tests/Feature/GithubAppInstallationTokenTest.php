<?php

use App\Models\GithubApp;
use App\Models\PrivateKey;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

beforeEach(function () {
    Sleep::fake();

    $this->team = Team::factory()->create();

    openssl_pkey_export(openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]), $pemKey);

    $this->githubApp = GithubApp::create([
        'name' => 'Private GitHub App',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'app_id' => 12345,
        'installation_id' => 67890,
        'private_key_id' => PrivateKey::create([
            'name' => 'GitHub App Key',
            'private_key' => $pemKey,
            'team_id' => $this->team->id,
        ])->id,
        'is_public' => false,
        'team_id' => $this->team->id,
    ]);

    Http::preventStrayRequests();
});

function fakeGithubInstallationTokenResponses(mixed $response): void
{
    Http::fake(['https://api.github.com/app/installations/*/access_tokens' => $response]);
}

function fakeTwoGithubInstallationTokens(): void
{
    fakeGithubInstallationTokenResponses(Http::sequence()
        ->push(['token' => 'first-token'], 201)
        ->push(['token' => 'second-token'], 201));
}

function githubInstallationTokenRequests(): int
{
    return Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/access_tokens'))->count();
}

it('reuses the installation token of the same GitHub App instance', function () {
    fakeTwoGithubInstallationTokens();

    expect(generateGithubInstallationToken($this->githubApp))->toBe('first-token')
        ->and(generateGithubInstallationToken($this->githubApp))->toBe('first-token')
        ->and(githubInstallationTokenRequests())->toBe(1);
});

it('requests a new installation token after the in-memory token expires', function () {
    fakeTwoGithubInstallationTokens();
    generateGithubInstallationToken($this->githubApp);

    $this->travel(51)->minutes();

    expect(generateGithubInstallationToken($this->githubApp))->toBe('second-token')
        ->and(githubInstallationTokenRequests())->toBe(2);
});

it('requests a new installation token when the GitHub App installation changes', function () {
    fakeTwoGithubInstallationTokens();
    generateGithubInstallationToken($this->githubApp);

    $this->githubApp->installation_id = 11111;

    expect(generateGithubInstallationToken($this->githubApp))->toBe('second-token')
        ->and(githubInstallationTokenRequests())->toBe(2);
});

it('does not share the installation token between GitHub App instances', function () {
    fakeTwoGithubInstallationTokens();
    generateGithubInstallationToken($this->githubApp);

    expect(generateGithubInstallationToken(GithubApp::find($this->githubApp->id)))->toBe('second-token')
        ->and(githubInstallationTokenRequests())->toBe(2);
});

it('retries the installation token request when GitHub returns a server error', function () {
    fakeGithubInstallationTokenResponses(Http::sequence()
        ->push('<html>Bad Gateway</html>', 502)
        ->push(['token' => 'retried-token'], 201));

    expect(generateGithubInstallationToken($this->githubApp))->toBe('retried-token')
        ->and(githubInstallationTokenRequests())->toBe(2);
});

it('does not retry the installation token request when GitHub rejects it', function () {
    fakeGithubInstallationTokenResponses(Http::response(['message' => 'Bad credentials'], 401));

    expect(fn () => generateGithubInstallationToken($this->githubApp))
        ->toThrow(RuntimeException::class, 'Failed to get installation token for Private GitHub App with error: Bad credentials')
        ->and(githubInstallationTokenRequests())->toBe(1);
});

it('reports the HTTP status when GitHub keeps failing without an error message', function () {
    fakeGithubInstallationTokenResponses(Http::response('<html>Service Unavailable</html>', 503));

    expect(fn () => generateGithubInstallationToken($this->githubApp))
        ->toThrow(RuntimeException::class, 'Failed to get installation token for Private GitHub App with error: HTTP 503')
        ->and(githubInstallationTokenRequests())->toBe(3);
});

it('requests the installation token without a separate GitHub clock request', function () {
    fakeGithubInstallationTokenResponses(Http::response(['token' => 'only-token'], 201));

    expect(generateGithubInstallationToken($this->githubApp))->toBe('only-token')
        ->and(Http::recorded())->toHaveCount(1);
});

it('reports the clock difference when GitHub rejects the token request of an out-of-sync server', function () {
    fakeGithubInstallationTokenResponses(Http::response(
        ['message' => "'Issued at' claim ('iat') must be an Integer representing a time in the past"],
        401,
        ['Date' => now()->addMinutes(5)->toRfc7231String()],
    ));

    expect(fn () => generateGithubInstallationToken($this->githubApp))
        ->toThrow(Exception::class, 'System time is out of sync with GitHub API time');
});

function githubRepositoryRequests(): int
{
    return Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/repos/'))->count();
}

it('retries a GitHub API read when GitHub returns a server error', function () {
    Http::fake([
        'https://api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 'only-token'], 201),
        'https://api.github.com/repos/*' => Http::sequence()
            ->push('<html>Bad Gateway</html>', 502)
            ->push(['id' => 42], 200),
    ]);

    expect(data_get(githubApi($this->githubApp, 'repos/coollabsio/coolify'), 'data.id'))->toBe(42)
        ->and(githubRepositoryRequests())->toBe(2)
        ->and(githubInstallationTokenRequests())->toBe(1);
});

it('retries a GitHub API read after the connection retry of the HTTP client also times out', function () {
    Http::fake([
        'https://api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 'only-token'], 201),
        'https://api.github.com/repos/*' => Http::sequence()
            ->pushFailedConnection('cURL error 28: Connection timed out after 10002 milliseconds')
            ->pushFailedConnection('cURL error 28: Connection timed out after 10002 milliseconds')
            ->push(['id' => 42], 200),
    ]);

    expect(data_get(githubApi($this->githubApp, 'repos/coollabsio/coolify'), 'data.id'))->toBe(42);
});

it('does not retry a GitHub API write when GitHub returns a server error', function () {
    Http::fake([
        'https://api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 'only-token'], 201),
        'https://api.github.com/repos/*' => Http::response('<html>Bad Gateway</html>', 502),
    ]);

    expect(fn () => githubApi($this->githubApp, 'repos/coollabsio/coolify/issues/1/comments', 'post', ['body' => 'Deployed']))
        ->toThrow(Exception::class, 'GitHub API call failed')
        ->and(githubRepositoryRequests())->toBe(1);
});
