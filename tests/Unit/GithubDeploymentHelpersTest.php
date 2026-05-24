<?php

use App\Models\GithubApp;
use App\Models\PrivateKey;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $team = Team::query()->create([
        'name' => 'GitHub Deployments Team',
        'description' => 'Team for GitHub deployment helper tests',
        'personal_team' => false,
        'show_boarding' => false,
    ]);

    $rsaKey = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    openssl_pkey_export($rsaKey, $pemKey);

    $privateKey = PrivateKey::create([
        'name' => 'GitHub Deployments Key',
        'private_key' => $pemKey,
        'team_id' => $team->id,
    ]);

    $this->githubApp = GithubApp::create([
        'name' => 'GitHub Deployments App',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'custom_user' => 'git',
        'custom_port' => 22,
        'app_id' => 12345,
        'installation_id' => 67890,
        'private_key_id' => $privateKey->id,
        'team_id' => $team->id,
        'is_system_wide' => false,
        'deployments' => 'write',
    ]);
});

it('detects github deployments permission from the app record', function () {
    expect(hasGitHubDeploymentsPermission($this->githubApp))->toBeTrue();
    expect(hasGitHubDeploymentsPermission($this->githubApp->forceFill(['deployments' => 'read'])))->toBeFalse();
});

it('uses the pull request ref for preview deployments', function () {
    expect(determineGitHubDeploymentRef(
        pullRequestId: 12,
        branch: 'main',
        commit: 'abc123def456',
        rollback: false,
    ))->toBe('refs/pull/12/head');
});

it('uses the branch name for non-rollback deployments', function () {
    expect(determineGitHubDeploymentRef(
        pullRequestId: 0,
        branch: 'main',
        commit: 'abc123def456',
        rollback: false,
    ))->toBe('main');
});

it('uses the exact commit for rollback deployments', function () {
    expect(determineGitHubDeploymentRef(
        pullRequestId: 0,
        branch: 'main',
        commit: 'abc123def456',
        rollback: true,
    ))->toBe('abc123def456');
});

it('creates GitHub deployments with the expected payload', function () {
    Http::fake([
        'https://api.github.com/zen' => Http::response('Keep it logically awesome.', 200, [
            'Date' => now()->toRfc7231String(),
        ]),
        'https://api.github.com/app/installations/67890/access_tokens' => Http::response([
            'token' => 'fake-installation-token',
        ], 201),
        'https://api.github.com/repos/coollabsio/coolify/deployments' => Http::response([
            'id' => 42,
        ], 201),
    ]);

    $deploymentId = createGitHubDeployment(
        source: $this->githubApp,
        repository: 'coollabsio/coolify',
        ref: 'abc123def456',
        environment: 'preview/pr-12',
        description: 'Coolify preview deployment for PR #12',
        transientEnvironment: true,
        productionEnvironment: false,
    );

    expect($deploymentId)->toBe(42);

    Http::assertSent(function (Request $request) {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.github.com/repos/coollabsio/coolify/deployments'
            && $request['ref'] === 'abc123def456'
            && $request['environment'] === 'preview/pr-12'
            && $request['auto_merge'] === false
            && $request['required_contexts'] === []
            && $request['transient_environment'] === true
            && $request['production_environment'] === false;
    });
});

it('updates GitHub deployment statuses with log and environment urls', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/zen' => Http::response('Keep it logically awesome.', 200, [
            'Date' => now()->toRfc7231String(),
        ]),
        'https://api.github.com/app/installations/67890/access_tokens' => Http::response([
            'token' => 'fake-installation-token',
        ], 201),
        'https://api.github.com/repos/coollabsio/coolify/deployments/42/statuses' => Http::response([
            'id' => 1,
        ], 201),
    ]);

    $updated = updateGitHubDeploymentStatus(
        source: $this->githubApp,
        repository: 'coollabsio/coolify',
        deploymentId: 42,
        state: 'success',
        description: 'Deployment finished successfully.',
        logUrl: 'https://coolify.example/deployments/42',
        environmentUrl: 'https://preview.example',
    );

    expect($updated)->toBeTrue();

    Http::assertSent(function (Request $request) {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.github.com/repos/coollabsio/coolify/deployments/42/statuses'
            && $request['state'] === 'success'
            && $request['description'] === 'Deployment finished successfully.'
            && $request['log_url'] === 'https://coolify.example/deployments/42'
            && $request['environment_url'] === 'https://preview.example';
    });
});
