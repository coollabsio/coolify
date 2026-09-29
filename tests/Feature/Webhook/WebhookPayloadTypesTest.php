<?php

use App\Actions\Application\CleanupPreviewDeployment;
use App\Jobs\ProcessGithubPullRequestWebhook;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Queue::fake();
    InstanceSettings::forceCreate(['id' => 0]);
});

function webhookTypesSha(): string
{
    return 'e83c5163316f89bfbde7d9ab23ca2e25604af290';
}

/**
 * An application that the handler deploys for a valid payload. App handlers
 * get a GitHub App or GitLab App source.
 */
function webhookTypesApplication(string $handler): Application
{
    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->settings->update([
        'is_reachable' => true,
        'is_usable' => true,
        'force_disabled' => false,
    ]);
    $destination = $server->standaloneDockers()->firstOrFail();

    $source = [];
    if ($handler === 'github app') {
        $githubApp = GithubApp::create([
            'uuid' => (string) str()->uuid(),
            'name' => 'github-app-payload-types',
            'api_url' => 'https://api.github.com',
            'html_url' => 'https://github.com',
            'app_id' => 1234567890,
            'webhook_secret' => 'github-app-secret',
            'team_id' => $team->id,
            'is_public' => false,
        ]);
        $source = ['source_id' => $githubApp->id, 'source_type' => GithubApp::class, 'repository_project_id' => 987654321];
    }
    if ($handler === 'gitlab app') {
        $gitlabApp = GitlabApp::create([
            'name' => 'gitlab-app-payload-types',
            'api_url' => 'https://gitlab.com/api/v4',
            'html_url' => 'https://gitlab.com',
            'custom_user' => 'git',
            'custom_port' => 22,
            'webhook_token' => 'gitlab-app-token',
            'team_id' => $team->id,
            'is_system_wide' => false,
            'is_public' => false,
        ]);
        $source = ['source_id' => $gitlabApp->id, 'source_type' => GitlabApp::class, 'repository_project_id' => 4242];
    }

    $application = Application::create(array_merge([
        'name' => 'webhook-payload-types-app',
        'git_repository' => 'https://github.com/test-org/test-repo',
        'git_branch' => 'main',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ], $source));
    $application->settings->update([
        'is_auto_deploy_enabled' => true,
        'is_preview_deployments_enabled' => true,
    ]);

    return $application->refresh();
}

/**
 * A valid payload for the handler and event.
 *
 * @return array<string, mixed>
 */
function webhookTypesPayload(string $handler, string $event, string $state = 'open'): array
{
    $sha = webhookTypesSha();
    $provider = str($handler)->before(' ')->value();

    if ($event === 'push') {
        return match ($provider) {
            'gitlab' => [
                'object_kind' => 'push',
                'ref' => 'refs/heads/main',
                'project' => ['id' => 4242, 'path_with_namespace' => 'test-org/test-repo'],
                'after' => $sha,
                'commits' => [],
            ],
            'bitbucket' => [
                'push' => ['changes' => [['new' => ['name' => 'main', 'target' => ['hash' => $sha]]]]],
                'repository' => ['full_name' => 'test-org/test-repo'],
            ],
            default => [
                'ref' => 'refs/heads/main',
                'repository' => ['id' => 987654321, 'full_name' => 'test-org/test-repo'],
                'after' => $sha,
                'commits' => [],
            ],
        };
    }

    $closed = $state === 'closed';

    return match ($provider) {
        'github' => [
            'action' => $closed ? 'closed' : 'opened',
            'number' => 42,
            'repository' => ['id' => 987654321, 'full_name' => 'test-org/test-repo'],
            'pull_request' => [
                'html_url' => 'https://github.com/test-org/test-repo/pull/42',
                'title' => 'Add feature',
                'author_association' => 'OWNER',
                'head' => ['ref' => 'feature', 'sha' => $sha, 'repo' => ['id' => 987654321, 'full_name' => 'test-org/test-repo']],
                'base' => ['ref' => 'main', 'repo' => ['id' => 987654321, 'full_name' => 'test-org/test-repo']],
            ],
        ],
        'gitlab' => [
            'object_kind' => 'merge_request',
            'project' => ['id' => 4242, 'path_with_namespace' => 'test-org/test-repo'],
            'object_attributes' => [
                'action' => $closed ? 'close' : 'open',
                'iid' => 7,
                'url' => 'https://gitlab.com/test-org/test-repo/-/merge_requests/7',
                'title' => 'Add feature',
                'source_branch' => 'feature',
                'target_branch' => 'main',
                'source_project_id' => 4242,
                'target_project_id' => 4242,
                'last_commit' => ['id' => $sha, 'message' => 'Add feature'],
            ],
        ],
        'gitea' => [
            'action' => $closed ? 'closed' : 'opened',
            'number' => 7,
            'repository' => ['id' => 55, 'full_name' => 'test-org/test-repo'],
            'pull_request' => [
                'html_url' => 'https://gitea.example.com/test-org/test-repo/pulls/7',
                'title' => 'Add feature',
                'head' => ['ref' => 'feature', 'sha' => $sha, 'repo' => ['id' => 55]],
                'base' => ['ref' => 'main', 'repo' => ['id' => 55]],
            ],
        ],
        'bitbucket' => [
            'pullrequest' => [
                'id' => 7,
                'title' => 'Add feature',
                'links' => ['html' => ['href' => 'https://bitbucket.org/test-org/test-repo/pull-requests/7']],
                'source' => ['branch' => ['name' => 'feature'], 'commit' => ['hash' => substr($sha, 0, 12)], 'repository' => ['uuid' => '{repo-uuid}']],
                'destination' => ['branch' => ['name' => 'main'], 'repository' => ['uuid' => '{repo-uuid}']],
            ],
            'repository' => ['full_name' => 'test-org/test-repo', 'uuid' => '{repo-uuid}'],
        ],
    };
}

/**
 * Send a correctly signed (or token-authenticated) delivery to the handler.
 *
 * @param  array<string, mixed>  $payload
 */
function webhookTypesSend(TestCase $test, string $handler, string $event, string $state, Application $application, array $payload): TestResponse
{
    [$provider, $mode] = explode(' ', $handler);
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $secret = match ($handler) {
        'github app' => 'github-app-secret',
        'gitlab app' => 'gitlab-app-token',
        default => $application->{"manual_webhook_secret_{$provider}"},
    };
    $signature = 'sha256='.hash_hmac('sha256', $body, $secret);
    $headers = match ($provider) {
        'github' => [
            'HTTP_X-GitHub-Event' => $event === 'push' ? 'push' : 'pull_request',
            'HTTP_X-Hub-Signature-256' => $signature,
            'HTTP_X-GitHub-Hook-Installation-Target-Id' => '1234567890',
        ],
        'gitea' => [
            'HTTP_X-Gitea-Event' => $event === 'push' ? 'push' : 'pull_request',
            'HTTP_X-Hub-Signature-256' => $signature,
        ],
        'gitlab' => ['HTTP_X-Gitlab-Token' => $secret],
        'bitbucket' => [
            'HTTP_X-Event-Key' => $event === 'push' ? 'repo:push' : ($state === 'closed' ? 'pullrequest:fulfilled' : 'pullrequest:created'),
            'HTTP_X-Hub-Signature' => $signature,
        ],
    };
    $uri = $mode === 'manual' ? "/webhooks/source/{$provider}/events/manual" : "/webhooks/source/{$provider}/events";

    return $test->call('POST', $uri, [], [], [], $headers + [
        'REMOTE_ADDR' => '203.0.113.20',
        'CONTENT_TYPE' => 'application/json',
    ], $body);
}

/**
 * Invalid values for a kind of payload field.
 *
 * @return array<string, mixed>
 */
function webhookTypesInvalidValues(string $kind): array
{
    $wrongTypes = [
        'an array' => ['x'],
        'an object' => ['key' => 'value'],
        'true' => true,
        'false' => false,
    ];

    return match ($kind) {
        'string' => $wrongTypes + ['an integer' => 123, 'a float' => 1.5],
        'required string' => $wrongTypes + ['an integer' => 123, 'null' => null],
        'branch' => $wrongTypes + ['an integer' => 123, 'null' => null],
        'sha' => $wrongTypes + [
            'an integer' => 1234567,
            'a short sha' => 'abc12',
            'an oversized sha' => str_repeat('a', 65),
            'a non-hex sha' => 'zzzzzzzz',
            'a shell expression' => '$(id)',
            'a git option' => '--upload-pack=touch',
        ],
        'id' => $wrongTypes + [
            'null' => null,
            'zero' => 0,
            'a negative integer' => -1,
            'a float' => 1.5,
            'text' => 'abc',
            'mixed text' => '12abc',
            'an oversized integer' => 2147483648,
            'an oversized numeric string' => '99999999999999999999999',
        ],
        'repository id' => $wrongTypes + ['zero' => 0, 'a negative integer' => -1, 'a float' => 1.5, 'text' => 'abc'],
        'url' => $wrongTypes + [
            'an integer' => 123,
            'a javascript url' => 'javascript:alert(1)',
            'a relative url' => '/test-org/test-repo/pull/1',
            'a url with spaces' => 'https://example.com/a b',
            'an oversized url' => 'https://example.com/'.str_repeat('a', 300),
        ],
    };
}

/**
 * Payload fields that each handler reads: [path, kind, expected message].
 * A null message means "Invalid '<path>'".
 *
 * @return array<string, array<int, array{0: string, 1: string, 2: ?string}>>
 */
function webhookTypesFields(): array
{
    return [
        'github manual|push|open' => [
            ['ref', 'branch', 'No branch'],
            ['after', 'sha', null],
            ['repository.full_name', 'required string', 'Invalid repository'],
        ],
        'github manual|pr|open' => [
            ['action', 'required string', null],
            ['number', 'id', null],
            ['pull_request.html_url', 'url', null],
            ['pull_request.title', 'string', null],
            ['pull_request.author_association', 'string', null],
            ['pull_request.head.ref', 'branch', 'No branch'],
            ['pull_request.base.ref', 'branch', 'No branch'],
            ['pull_request.head.sha', 'sha', null],
            ['after', 'sha', null],
            ['before', 'sha', null],
            ['pull_request.head.repo.id', 'repository id', null],
            ['pull_request.base.repo.id', 'repository id', null],
            ['repository.full_name', 'required string', 'Invalid repository'],
        ],
        'github manual|pr|closed' => [
            ['action', 'required string', null],
            ['number', 'id', null],
            ['pull_request.html_url', 'url', null],
        ],
        'github app|push|open' => [
            ['repository.id', 'id', 'Nothing to do.'],
            ['ref', 'branch', 'No id or branch'],
            ['after', 'sha', null],
        ],
        'github app|pr|open' => [
            ['repository.id', 'id', 'Nothing to do.'],
            ['action', 'required string', null],
            ['number', 'id', null],
            ['pull_request.html_url', 'url', null],
            ['pull_request.title', 'string', null],
            ['pull_request.author_association', 'string', null],
            ['pull_request.head.ref', 'branch', 'No id or branch'],
            ['pull_request.base.ref', 'branch', 'No id or branch'],
            ['pull_request.head.sha', 'sha', null],
            ['after', 'sha', null],
            ['before', 'sha', null],
            ['pull_request.head.repo.id', 'repository id', null],
            ['repository.full_name', 'required string', 'Invalid repository'],
        ],
        'github app|pr|closed' => [
            ['action', 'required string', null],
            ['number', 'id', null],
            ['pull_request.html_url', 'url', null],
        ],
        'gitlab manual|push|open' => [
            ['object_kind', 'string', 'Event not allowed'],
            ['ref', 'branch', 'No branch'],
            ['after', 'sha', null],
            ['project.path_with_namespace', 'required string', 'Invalid repository'],
        ],
        'gitlab manual|pr|open' => [
            ['object_attributes.action', 'string', null],
            ['object_attributes.iid', 'id', null],
            ['object_attributes.url', 'url', null],
            ['object_attributes.title', 'string', null],
            ['object_attributes.source_branch', 'branch', 'No branch'],
            ['object_attributes.target_branch', 'branch', 'No branch'],
            ['object_attributes.last_commit.id', 'sha', null],
        ],
        'gitlab manual|pr|closed' => [
            ['object_attributes.action', 'string', null],
            ['object_attributes.iid', 'id', null],
        ],
        'gitlab app|push|open' => [
            ['object_kind', 'string', 'Event not allowed'],
            ['project.id', 'id', null],
            ['ref', 'branch', 'No branch'],
            ['after', 'sha', null],
        ],
        'gitlab app|pr|open' => [
            ['project.id', 'id', null],
            ['object_attributes.action', 'string', null],
            ['object_attributes.iid', 'id', null],
            ['object_attributes.url', 'url', null],
            ['object_attributes.title', 'string', null],
            ['object_attributes.source_branch', 'branch', 'No branch'],
            ['object_attributes.target_branch', 'branch', 'No branch'],
            ['object_attributes.last_commit.id', 'sha', null],
        ],
        'gitlab app|pr|closed' => [
            ['object_attributes.action', 'string', null],
            ['object_attributes.iid', 'id', null],
        ],
        'gitea manual|push|open' => [
            ['ref', 'branch', 'No branch'],
            ['after', 'sha', null],
            ['repository.full_name', 'required string', 'Invalid repository'],
        ],
        'gitea manual|pr|open' => [
            ['action', 'string', null],
            ['number', 'id', null],
            ['pull_request.html_url', 'url', null],
            ['pull_request.title', 'string', null],
            ['pull_request.head.ref', 'branch', 'No branch'],
            ['pull_request.base.ref', 'branch', 'No branch'],
            ['pull_request.head.sha', 'sha', null],
            ['repository.full_name', 'required string', 'Invalid repository'],
        ],
        'gitea manual|pr|closed' => [
            ['action', 'string', null],
            ['number', 'id', null],
        ],
        'bitbucket manual|push|open' => [
            ['push.changes.0.new.name', 'branch', 'No branch'],
            ['push.changes.0.new.target.hash', 'sha', null],
            ['repository.full_name', 'required string', 'Invalid repository'],
        ],
        'bitbucket manual|pr|open' => [
            ['pullrequest.id', 'id', null],
            ['pullrequest.links.html.href', 'url', null],
            ['pullrequest.title', 'string', null],
            ['pullrequest.destination.branch.name', 'branch', 'No branch'],
            ['pullrequest.source.commit.hash', 'sha', null],
            ['repository.full_name', 'required string', 'Invalid repository'],
        ],
        'bitbucket manual|pr|closed' => [
            ['pullrequest.id', 'id', null],
        ],
    ];
}

/**
 * @return Generator<string, array{0: string, 1: string, 2: string, 3: string, 4: mixed, 5: string}>
 */
function webhookTypesInvalidDeliveries(): Generator
{
    foreach (webhookTypesFields() as $target => $fields) {
        [$handler, $event, $state] = explode('|', $target);
        foreach ($fields as [$path, $kind, $message]) {
            foreach (webhookTypesInvalidValues($kind) as $label => $value) {
                yield "{$handler} {$event} {$state}: {$path} is {$label}" => [
                    $handler, $event, $state, $path, $value, $message ?? "Invalid '{$path}'",
                ];
            }
        }
    }
}

function webhookTypesCreatePreview(Application $application, string $handler): ApplicationPreview
{
    return ApplicationPreview::create([
        'git_type' => str($handler)->before(' ')->value(),
        'application_id' => $application->id,
        'pull_request_id' => 7,
        'pull_request_html_url' => 'https://example.com/pull/7',
    ]);
}

describe('Webhook payload value types', function () {
    test('a signed delivery with an invalid value gets a clean response and does not deploy', function (string $handler, string $event, string $state, string $path, mixed $value, string $message) {
        $application = webhookTypesApplication($handler);
        $closedPreview = $event === 'pr' && $state === 'closed' && ! str_starts_with($handler, 'github')
            ? webhookTypesCreatePreview($application, $handler)
            : null;
        CleanupPreviewDeployment::shouldNotRun();

        $payload = webhookTypesPayload($handler, $event, $state);
        data_set($payload, $path, $value);

        $response = webhookTypesSend($this, $handler, $event, $state, $application, $payload);

        $response->assertOk();
        expect($response->getContent())->toContain($message)
            ->not->toContain('queued');
        expect(ApplicationDeploymentQueue::query()->where('application_id', $application->id)->exists())->toBeFalse();
        expect(ApplicationPreview::query()->where('application_id', $application->id)->count())->toBe($closedPreview ? 1 : 0);
        Queue::assertNotPushed(ProcessGithubPullRequestWebhook::class);
    })->with(fn (): Generator => webhookTypesInvalidDeliveries());

    test('a valid push is deployed with its commit', function (string $handler) {
        $application = webhookTypesApplication($handler);

        $response = webhookTypesSend($this, $handler, 'push', 'open', $application, webhookTypesPayload($handler, 'push'));

        $response->assertOk();
        expect(ApplicationDeploymentQueue::query()->where('application_id', $application->id)->pluck('commit')->all())
            ->toBe([webhookTypesSha()]);
    })->with(['github manual', 'github app', 'gitlab manual', 'gitlab app', 'gitea manual', 'bitbucket manual']);

    test('a valid github pull request is queued for processing', function (string $handler, string $state) {
        $application = webhookTypesApplication($handler);

        $response = webhookTypesSend($this, $handler, 'pr', $state, $application, webhookTypesPayload($handler, 'pr', $state));

        $response->assertOk();
        expect($response->getContent())->toContain('PR webhook received');
        Queue::assertPushed(ProcessGithubPullRequestWebhook::class, fn (ProcessGithubPullRequestWebhook $job): bool => $job->applicationId === $application->id
            && $job->action === ($state === 'closed' ? 'closed' : 'opened')
            && $job->pullRequestId === 42
            && $job->pullRequestHtmlUrl === 'https://github.com/test-org/test-repo/pull/42'
            && $job->pullRequestTitle === 'Add feature'
            && $job->commitSha === webhookTypesSha()
            && $job->authorAssociation === 'OWNER'
            && $job->fullName === 'test-org/test-repo'
            && $job->isForkPullRequest === false);
    })->with(['github manual', 'github app'])->with(['open', 'closed']);

    test('a github pull request with a numeric string number and sync shas is queued', function (string $handler) {
        $application = webhookTypesApplication($handler);
        $payload = webhookTypesPayload($handler, 'pr');
        $payload['action'] = 'synchronize';
        $payload['number'] = '42';
        $payload['before'] = str_repeat('b', 40);
        $payload['after'] = webhookTypesSha();

        webhookTypesSend($this, $handler, 'pr', 'open', $application, $payload)->assertOk();

        Queue::assertPushed(ProcessGithubPullRequestWebhook::class, fn (ProcessGithubPullRequestWebhook $job): bool => $job->pullRequestId === 42
            && $job->beforeSha === str_repeat('b', 40)
            && $job->afterSha === webhookTypesSha());
    })->with(['github manual', 'github app']);

    test('a valid pull request opens a preview deployment', function (string $handler, string $commit) {
        $application = webhookTypesApplication($handler);

        $response = webhookTypesSend($this, $handler, 'pr', 'open', $application, webhookTypesPayload($handler, 'pr'));

        $response->assertOk();
        expect(ApplicationPreview::query()->where('application_id', $application->id)->where('pull_request_id', 7)->exists())->toBeTrue();
        $deployment = ApplicationDeploymentQueue::query()->where('application_id', $application->id)->sole();
        expect($deployment->pull_request_id)->toBe(7)
            ->and($deployment->commit)->toBe($commit);
    })->with([
        'gitlab manual' => ['gitlab manual', webhookTypesSha()],
        'gitlab app' => ['gitlab app', webhookTypesSha()],
        // Gitea sends the head commit in pull_request.head.sha.
        'gitea manual' => ['gitea manual', webhookTypesSha()],
        'bitbucket manual' => ['bitbucket manual', substr(webhookTypesSha(), 0, 12)],
    ]);

    test('a merge request id as a numeric string is accepted', function (string $handler) {
        $application = webhookTypesApplication($handler);
        $payload = webhookTypesPayload($handler, 'pr');
        data_set($payload, 'object_attributes.iid', '7');

        webhookTypesSend($this, $handler, 'pr', 'open', $application, $payload)->assertOk();

        expect(ApplicationPreview::query()->where('application_id', $application->id)->where('pull_request_id', 7)->exists())->toBeTrue();
    })->with(['gitlab manual', 'gitlab app']);

    test('a pull request without a url opens a preview with an empty url', function () {
        $application = webhookTypesApplication('gitlab manual');
        $payload = webhookTypesPayload('gitlab manual', 'pr');
        unset($payload['object_attributes']['url']);

        webhookTypesSend($this, 'gitlab manual', 'pr', 'open', $application, $payload)->assertOk();

        expect(ApplicationPreview::query()->where('application_id', $application->id)->sole()->pull_request_html_url)->toBe('');
    });

    test('an unsigned github delivery in dev mode with an invalid value gets a clean response', function (string $event, string $path) {
        config()->set('app.env', 'local');
        $application = webhookTypesApplication('github manual');
        $payload = webhookTypesPayload('github manual', $event);
        data_set($payload, $path, ['x']);

        $response = $this->call('POST', '/webhooks/source/github/events/manual', [], [], [], [
            'HTTP_X-GitHub-Event' => $event === 'push' ? 'push' : 'pull_request',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($payload, JSON_THROW_ON_ERROR));

        $response->assertOk();
        expect($response->getContent())->toContain("Invalid '{$path}'");
        expect(ApplicationDeploymentQueue::query()->where('application_id', $application->id)->exists())->toBeFalse();
        Queue::assertNotPushed(ProcessGithubPullRequestWebhook::class);
    })->with([
        'push after' => ['push', 'after'],
        'pull request number' => ['pr', 'number'],
        'pull request html_url' => ['pr', 'pull_request.html_url'],
    ]);

    test('a valid closed pull request cleans up the preview deployment', function (string $handler) {
        $application = webhookTypesApplication($handler);
        webhookTypesCreatePreview($application, $handler);
        CleanupPreviewDeployment::shouldRun()->once();

        $response = webhookTypesSend($this, $handler, 'pr', 'closed', $application, webhookTypesPayload($handler, 'pr', 'closed'));

        $response->assertOk();
        expect($response->getContent())->toContain('Preview deployment closed');
    })->with(['gitlab manual', 'gitlab app', 'gitea manual', 'bitbucket manual']);
});
