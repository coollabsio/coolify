<?php

use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use App\Rules\SafeExternalUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('rejects unsafe Git source targets when a request is built', function () {
    expect(fn () => Http::GitHub('http://169.254.169.254', 'secret'))
        ->toThrow(RuntimeException::class);
});

it('disables redirects and pins public GitHub requests without losing authorization', function () {
    $request = Http::GitHub('https://api.github.com', 'secret');
    $options = $request->getOptions();

    expect($options['allow_redirects'])->toBeFalse()
        ->and($options['curl'][CURLOPT_RESOLVE][0])->toStartWith('api.github.com:443:')
        ->and($options['headers']['Authorization'])->toBe('Bearer secret');
});

it('uses the same guard and bearer header for GitLab requests', function () {
    $request = Http::GitLab('https://gitlab.com/api/v4', 'gitlab-secret');
    $options = $request->getOptions();

    expect($options['allow_redirects'])->toBeFalse()
        ->and($options['curl'][CURLOPT_RESOLVE][0])->toStartWith('gitlab.com:443:')
        ->and($options['headers']['Authorization'])->toBe('Bearer gitlab-secret');
});

it('rejects a hostname that resolves to a private IP at request time', function () {
    expect(fn () => SafeExternalUrl::httpClientOptions(
        'https://api.github.com',
        resolver: fn (string $host): array => ['169.254.169.254'],
    ))->toThrow(RuntimeException::class, 'unsafe IP address');
});

it('requires source administration rather than ordinary team membership to configure a source', function () {
    $team = Team::factory()->create();
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team->members()->attach($owner->id, ['role' => 'owner']);
    $team->members()->attach($member->id, ['role' => 'member']);

    $this->actingAs($member);
    session(['currentTeam' => $team]);
    expect($member->can('create', GithubApp::class))->toBeFalse();

    $this->actingAs($owner);
    session(['currentTeam' => $team]);
    expect($owner->can('create', GithubApp::class))->toBeTrue();
});

it('does not send a manifest conversion request to an unsafe saved source URL', function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);
    $app = GithubApp::create([
        'name' => 'Unsafe source',
        'api_url' => 'http://169.254.169.254',
        'html_url' => 'https://github.com',
        'team_id' => $team->id,
    ]);
    Cache::put('github-app-setup-state:'.hash('sha256', 'safe-test-state'), [
        'action' => 'manifest',
        'github_app_id' => $app->id,
        'team_id' => $team->id,
    ], now()->addMinutes(10));
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response([], 200)]);

    $this->actingAs($user);
    session(['currentTeam' => $team]);
    $this->get('/webhooks/source/github/redirect?code=test-code&state=safe-test-state');

    Http::assertNothingSent();
});

it('does not send a GitLab token refresh request to an unsafe saved source URL', function () {
    $team = Team::factory()->create();
    $source = GitlabApp::create([
        'name' => 'Unsafe GitLab source',
        'api_url' => 'https://gitlab.com/api/v4',
        'html_url' => 'http://169.254.169.254',
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'refresh_token' => 'refresh-token',
        'expires_at' => 0,
        'team_id' => $team->id,
    ]);
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(['access_token' => 'unsafe-token'], 200)]);

    expect(fn () => refreshGitlabToken($source))->toThrow(RuntimeException::class);
    Http::assertNothingSent();
});
