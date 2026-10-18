<?php

use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use App\Rules\SafeExternalUrl;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('rejects unsafe Git source targets when a request is built', function () {
    expect(fn () => Http::GitHub('http://169.254.169.254', 'secret'))
        ->toThrow(RuntimeException::class);
});

it('limits redirects and pins public GitHub requests without losing authorization', function () {
    $request = Http::GitHub('https://api.github.com', 'secret');
    $options = $request->getOptions();

    expect($options['allow_redirects'])->toMatchArray(['max' => 5, 'strict' => true])
        ->and($options['curl'][CURLOPT_RESOLVE][0])->toStartWith('api.github.com:443:')
        ->and($options['headers']['Authorization'])->toBe('Bearer secret');
});

it('uses the same guard and bearer header for GitLab requests', function () {
    $request = Http::GitLab('https://gitlab.com/api/v4', 'gitlab-secret');
    $options = $request->getOptions();

    expect($options['allow_redirects'])->toMatchArray(['max' => 5, 'strict' => true])
        ->and($options['curl'][CURLOPT_RESOLVE][0])->toStartWith('gitlab.com:443:')
        ->and($options['headers']['Authorization'])->toBe('Bearer gitlab-secret');
});

it('follows a same-host redirect for a renamed GitHub repository', function () {
    $transport = new MockHandler([
        new Response(301, ['Location' => 'https://api.github.com/repositories/2126244']),
        new Response(200, ['Content-Type' => 'application/json'], json_encode(['full_name' => 'twbs/bootstrap'])),
    ]);

    $response = Http::GitHub('https://api.github.com', 'secret')->setHandler($transport)->get('repos/twitter/bootstrap');

    expect($response->status())->toBe(200)
        ->and($response->json('full_name'))->toBe('twbs/bootstrap')
        ->and((string) $transport->getLastRequest()->getUri())->toBe('https://api.github.com/repositories/2126244')
        ->and($transport->getLastRequest()->getHeaderLine('Authorization'))->toBe('Bearer secret');
});

it('keeps the request method when GitHub redirects a POST request', function () {
    $transport = new MockHandler([
        new Response(301, ['Location' => 'https://api.github.com/repositories/2126244/dispatches']),
        new Response(204),
    ]);

    Http::GitHub('https://api.github.com', 'secret')->setHandler($transport)->post('repos/twitter/bootstrap/dispatches', ['event_type' => 'deploy']);

    expect((string) $transport->getLastRequest()->getUri())->toBe('https://api.github.com/repositories/2126244/dispatches')
        ->and($transport->getLastRequest()->getMethod())->toBe('POST')
        ->and((string) $transport->getLastRequest()->getBody())->toBe('{"event_type":"deploy"}');
});

it('refuses a Git source redirect to another host, scheme or port', function (string $location) {
    $transport = new MockHandler([
        new Response(301, ['Location' => $location]),
        new Response(200),
    ]);

    expect(fn () => Http::GitHub('https://api.github.com', 'secret')->setHandler($transport)->get('repos/twitter/bootstrap'))
        ->toThrow(ConnectionException::class, 'another host')
        ->and($transport->count())->toBe(1);
})->with([
    'metadata address' => ['http://169.254.169.254/latest/meta-data'],
    'other host' => ['https://attacker.example/repositories/2126244'],
    'other scheme' => ['http://api.github.com/repositories/2126244'],
    'other port' => ['https://api.github.com:8443/repositories/2126244'],
]);

it('stops after five Git source redirects', function () {
    $transport = new MockHandler(array_fill(0, 7, new Response(301, ['Location' => 'https://api.github.com/loop'])));

    expect(fn () => Http::GitHub('https://api.github.com', 'secret')->setHandler($transport)->get('loop'))
        ->toThrow(ConnectionException::class, 'Will not follow more than 5 redirects');
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
