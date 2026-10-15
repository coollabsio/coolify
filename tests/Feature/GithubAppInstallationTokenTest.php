<?php

use App\Models\GithubApp;
use App\Models\PrivateKey;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
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

    Http::fake([
        'https://api.github.com/zen' => fn () => Http::response('Keep it logically awesome.', 200, [
            'Date' => now()->toRfc7231String(),
        ]),
        'https://api.github.com/app/installations/*/access_tokens' => Http::sequence()
            ->push(['token' => 'first-token'], 201)
            ->push(['token' => 'second-token'], 201),
    ]);
});

function githubInstallationTokenRequests(): int
{
    return Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/access_tokens'))->count();
}

it('reuses the installation token of the same GitHub App instance', function () {
    expect(generateGithubInstallationToken($this->githubApp))->toBe('first-token')
        ->and(generateGithubInstallationToken($this->githubApp))->toBe('first-token')
        ->and(githubInstallationTokenRequests())->toBe(1);
});

it('requests a new installation token after the in-memory token expires', function () {
    generateGithubInstallationToken($this->githubApp);

    $this->travel(51)->minutes();

    expect(generateGithubInstallationToken($this->githubApp))->toBe('second-token')
        ->and(githubInstallationTokenRequests())->toBe(2);
});

it('requests a new installation token when the GitHub App installation changes', function () {
    generateGithubInstallationToken($this->githubApp);

    $this->githubApp->installation_id = 11111;

    expect(generateGithubInstallationToken($this->githubApp))->toBe('second-token')
        ->and(githubInstallationTokenRequests())->toBe(2);
});

it('does not share the installation token between GitHub App instances', function () {
    generateGithubInstallationToken($this->githubApp);

    expect(generateGithubInstallationToken(GithubApp::find($this->githubApp->id)))->toBe('second-token')
        ->and(githubInstallationTokenRequests())->toBe(2);
});
