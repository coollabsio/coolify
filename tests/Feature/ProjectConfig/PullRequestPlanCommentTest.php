<?php

use App\Jobs\CommentConfigPlanJob;
use App\Models\EnvironmentConfigLink;
use App\Models\EnvironmentConfigRevision;
use App\Models\GithubApp;
use App\Models\PrivateKey;
use App\Services\ProjectConfig\RepositoryFile;
use App\Services\ProjectConfig\RepositoryFileReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->fx = projectConfigFixture();
    $this->githubApp = GithubApp::create([
        'name' => 'acme-app', 'api_url' => 'https://api.github.com', 'html_url' => 'https://github.com',
        'app_id' => 4242, 'installation_id' => 99, 'webhook_secret' => 'app-secret', 'is_public' => true,
        'team_id' => $this->fx['team']->id, 'private_key_id' => PrivateKey::factory()->create(['team_id' => $this->fx['team']->id])->id,
    ]);
    $this->link = EnvironmentConfigLink::factory()->create([
        'environment_id' => $this->fx['environment']->id, 'mode' => 'git', 'source_type' => GithubApp::class, 'source_id' => $this->githubApp->id,
        'repository' => 'acme/api', 'branch' => 'main', 'read_server_id' => $this->fx['server']->id,
    ])->fresh();
    app()->instance(RepositoryFileReader::class, new class extends RepositoryFileReader
    {
        public function read(EnvironmentConfigLink $link, ?string $commit = null): RepositoryFile
        {
            return new RepositoryFile(json_encode(['version' => 1, 'resources' => ['api' => [
                'type' => 'application', 'server' => 'prod-1', 'build' => ['pack' => 'dockerfile'], 'ports' => ['exposes' => '3000'],
                'source' => ['repository' => 'https://github.com/acme/api', 'branch' => 'main'],
                'env' => ['TOKEN' => ['value' => 'pr-secret-value']],
            ]], 'environments' => ['production' => ['branch' => 'main']]]), (string) $commit, false);
        }
    });
});

function prCommentFake(array &$existing): void
{
    Http::fake([
        '*/repos/acme/api/issues/7/comments*' => function (Request $request) use (&$existing) {
            return $request->method() === 'GET' ? Http::response($existing) : Http::response(['id' => 501], 201);
        },
        '*/repos/acme/api/issues/comments/*' => Http::response(['id' => 501]),
        '*' => Http::response([], 200),
    ]);
}

it('posts one plan comment without values, and edits it on the next push', function () {
    $existing = [];
    prCommentFake($existing);
    CommentConfigPlanJob::dispatchSync($this->link, 7, str_repeat('b', 40));

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_contains($request->url(), '/repos/acme/api/issues/7/comments')
        && str_contains($request['body'], '<!-- coolify-config-plan:'.$this->link->uuid.' -->')
        && str_contains($request['body'], 'api')
        && ! str_contains($request['body'], 'pr-secret-value'));

    $existing = [['id' => 501, 'body' => "<!-- coolify-config-plan:{$this->link->uuid} -->\nold"]];
    CommentConfigPlanJob::dispatchSync($this->link, 7, str_repeat('c', 40));

    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH' && str_contains($request->url(), '/repos/acme/api/issues/comments/501'));
    expect(EnvironmentConfigRevision::count())->toBe(0);
});

it('dispatches a comment for a same-repository pull request only', function () {
    Queue::fake([CommentConfigPlanJob::class]);
    $send = function (string $headRepo) {
        $payload = json_encode([
            'action' => 'opened', 'number' => 7, 'repository' => ['id' => 777, 'full_name' => 'acme/api'], 'installation' => ['id' => 99],
            'pull_request' => ['html_url' => 'https://github.com/acme/api/pull/7', 'title' => 'x',
                'head' => ['ref' => 'feature', 'sha' => str_repeat('d', 40), 'repo' => ['full_name' => $headRepo, 'fork' => $headRepo !== 'acme/api']],
                'base' => ['ref' => 'main', 'repo' => ['full_name' => 'acme/api']]],
        ]);

        return $this->call('POST', '/webhooks/source/github/events', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_GITHUB_EVENT' => 'pull_request',
            'HTTP_X_GITHUB_HOOK_INSTALLATION_TARGET_ID' => '4242',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $payload, 'app-secret'),
        ], $payload);
    };

    $send('fork-owner/api');
    Queue::assertNotPushed(CommentConfigPlanJob::class);

    $send('acme/api');
    Queue::assertPushed(CommentConfigPlanJob::class, fn ($job) => $job->link->is($this->link) && $job->pullRequestId === 7 && $job->headSha === str_repeat('d', 40));
});

it('does not let file keys and messages inject Markdown into the comment', function () {
    app()->instance(RepositoryFileReader::class, new class extends RepositoryFileReader
    {
        public function read(EnvironmentConfigLink $link, ?string $commit = null): RepositoryFile
        {
            return new RepositoryFile(json_encode(['version' => 1, 'project' => "**bold**\n[link](https://evil.example) <img src=x>", 'resources' => [
                "x`[pwn](https://evil.example)`\n# Owned" => ['type' => 'application'],
            ]]), (string) $commit, false);
        }
    });
    $existing = [];
    prCommentFake($existing);

    CommentConfigPlanJob::dispatchSync($this->link, 7, str_repeat('b', 40));

    Http::assertSent(function (Request $request) {
        if ($request->method() !== 'POST') {
            return false;
        }
        $body = $request['body'];
        // Outside code spans, a link must be escaped.
        $outsideCode = preg_replace('/`[^`\n]*`/', '', $body);

        return str_contains($body, 'The file is not valid')
            && ! str_contains($outsideCode, '[pwn](')
            && ! str_contains($outsideCode, '[link](')
            && ! str_contains($outsideCode, '**bold**')
            && ! preg_match('/(?<!\\\\)<img/', $outsideCode)
            && ! preg_match('/^# Owned/m', $body);
    });
});
