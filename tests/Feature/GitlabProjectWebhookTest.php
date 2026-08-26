<?php

use App\Models\GitlabApp;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Model::encryptUsing(new Encrypter(str_repeat('a', 32), 'AES-256-CBC'));
});

afterEach(function () {
    Model::encryptUsing(null);
});

function connectedGitlabSource(array $overrides = []): GitlabApp
{
    return new GitlabApp(array_merge([
        'api_url' => 'https://gitlab.example.test/api/v4',
        'html_url' => 'https://gitlab.example.test',
        'redirect_uri' => 'https://coolify.example.test/webhooks/source/gitlab/redirect',
        'webhook_token' => 'webhook-token-value', // ggignore
        'access_token' => str_repeat('t', 20), // ggignore
        'refresh_token' => str_repeat('r', 20), // ggignore
        'expires_at' => time() + 3600,
    ], $overrides));
}

it('derives the events webhook URL from the OAuth redirect URI', function () {
    expect(gitlabWebhookUrl(connectedGitlabSource()))
        ->toBe('https://coolify.example.test/webhooks/source/gitlab/events');
});

it('falls back to the instance URL when the source has no redirect URI', function () {
    config()->set('app.url', 'https://fallback.example.test/');

    expect(gitlabWebhookUrl(connectedGitlabSource(['redirect_uri' => null])))
        ->toBe('https://fallback.example.test/webhooks/source/gitlab/events');
});

it('creates the project webhook when GitLab has none', function () {
    Http::fake(fn (Request $request) => $request->method() === 'POST'
        ? Http::response(['id' => 7], 201)
        : Http::response([], 200));

    $result = syncGitlabProjectWebhook(connectedGitlabSource(), 42);

    expect($result)->toBe([
        'status' => 'created',
        'id' => 7,
        'url' => 'https://coolify.example.test/webhooks/source/gitlab/events',
    ]);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://gitlab.example.test/api/v4/projects/42/hooks'
        && $request['url'] === 'https://coolify.example.test/webhooks/source/gitlab/events'
        && $request['token'] === 'webhook-token-value' // ggignore
        && $request['push_events'] === true
        && $request['merge_requests_events'] === true
        && $request['enable_ssl_verification'] === true);
});

it('updates the existing project webhook instead of adding a duplicate', function () {
    Http::fake(fn (Request $request) => $request->method() === 'GET'
        ? Http::response([
            ['id' => 3, 'url' => 'https://someone-else.example.test/hook'],
            ['id' => 9, 'url' => 'https://coolify.example.test/webhooks/source/gitlab/events'],
        ], 200)
        : Http::response(['id' => 9], 200));

    $result = syncGitlabProjectWebhook(connectedGitlabSource(), 42);

    expect($result['status'])->toBe('updated');
    expect($result['id'])->toBe(9);

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && $request->url() === 'https://gitlab.example.test/api/v4/projects/42/hooks/9');
    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
});

it('disables SSL verification hints for plain http instances', function () {
    Http::fake(fn (Request $request) => $request->method() === 'POST'
        ? Http::response(['id' => 1], 201)
        : Http::response([], 200));

    syncGitlabProjectWebhook(connectedGitlabSource([
        'redirect_uri' => 'http://192.168.1.10:8000/webhooks/source/gitlab/redirect',
    ]), 42);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request['enable_ssl_verification'] === false);
});

it('refuses to configure a webhook for a source that is not connected', function () {
    Http::fake();

    syncGitlabProjectWebhook(connectedGitlabSource(['access_token' => null, 'refresh_token' => null]), 42);
})->throws(RuntimeException::class, 'not connected');

it('removes only the webhook that points at this Coolify instance', function () {
    Http::fake(fn (Request $request) => $request->method() === 'GET'
        ? Http::response([
            ['id' => 3, 'url' => 'https://someone-else.example.test/hook'],
            ['id' => 9, 'url' => 'https://coolify.example.test/webhooks/source/gitlab/events'],
        ], 200)
        : Http::response(null, 204));

    expect(removeGitlabProjectWebhook(connectedGitlabSource(), 42))->toBeTrue();

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
        && $request->url() === 'https://gitlab.example.test/api/v4/projects/42/hooks/9');
});

it('reports nothing to remove when GitLab has no Coolify webhook', function () {
    Http::fake(fn (Request $request) => Http::response([
        ['id' => 3, 'url' => 'https://someone-else.example.test/hook'],
    ], 200));

    expect(removeGitlabProjectWebhook(connectedGitlabSource(), 42))->toBeFalse();

    Http::assertNotSent(fn (Request $request) => $request->method() === 'DELETE');
});
