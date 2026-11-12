<?php

use App\Jobs\DeleteResourceJob;
use App\Jobs\ProcessGithubPullRequestWebhook;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Queue::fake();

    $this->retrievedApplicationIds = collect();
    Event::listen('eloquent.retrieved: '.Application::class, fn (Application $application) => $this->retrievedApplicationIds->push($application->id));
});

function manualLookupApplication(string $gitRepository, string $branch = 'main'): Application
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

    return Application::create([
        'name' => 'manual-lookup-app',
        'git_repository' => $gitRepository,
        'git_branch' => $branch,
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ])->refresh();
}

function sendManualLookupPush(TestCase $test, string $provider, string $secret, string $repository = 'acme/app', string $branch = 'main'): TestResponse
{
    $server = ['REMOTE_ADDR' => '203.0.113.20', 'CONTENT_TYPE' => 'application/json'];

    if ($provider === 'gitlab') {
        $payload = json_encode([
            'object_kind' => 'push',
            'ref' => "refs/heads/{$branch}",
            'project' => ['path_with_namespace' => $repository],
            'after' => 'abc1234',
            'commits' => [],
        ]);

        return $test->call('POST', '/webhooks/source/gitlab/events/manual', [], [], [], $server + ['HTTP_X-Gitlab-Token' => $secret], $payload);
    }

    if ($provider === 'bitbucket') {
        $payload = json_encode([
            'push' => ['changes' => [['new' => ['name' => $branch, 'target' => ['hash' => 'abc1234']]]]],
            'repository' => ['full_name' => $repository],
        ]);

        return $test->call('POST', '/webhooks/source/bitbucket/events/manual', [], [], [], $server + [
            'HTTP_X-Event-Key' => 'repo:push',
            'HTTP_X-Hub-Signature' => 'sha256='.hash_hmac('sha256', $payload, $secret),
        ], $payload);
    }

    $payload = json_encode([
        'ref' => "refs/heads/{$branch}",
        'repository' => ['full_name' => $repository],
        'after' => 'abc1234',
        'commits' => [],
    ]);

    return $test->call('POST', "/webhooks/source/{$provider}/events/manual", [], [], [], $server + [
        $provider === 'github' ? 'HTTP_X-GitHub-Event' : 'HTTP_X-Gitea-Event' => 'push',
        'HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $payload, $secret),
    ], $payload);
}

function sendManualLookupGithubPullRequest(TestCase $test, Application $application, string $action, string $baseBranch = 'main'): TestResponse
{
    $payload = json_encode([
        'action' => $action,
        'number' => 42,
        'repository' => ['id' => 987654321, 'full_name' => 'acme/app'],
        'pull_request' => [
            'html_url' => 'https://github.com/acme/app/pull/42',
            'title' => 'Change',
            'head' => ['ref' => 'feature', 'sha' => 'e83c5163316f89bfbde7d9ab23ca2e25604af290', 'repo' => ['id' => 987654321]],
            'base' => ['ref' => $baseBranch, 'repo' => ['id' => 987654321]],
        ],
    ], JSON_THROW_ON_ERROR);

    return $test->call('POST', '/webhooks/source/github/events/manual', [], [], [], [
        'HTTP_X-GitHub-Event' => 'pull_request',
        'HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $payload, $application->manual_webhook_secret_github),
        'CONTENT_TYPE' => 'application/json',
    ], $payload);
}

function manualLookupDeployed(Application $application): bool
{
    return ApplicationDeploymentQueue::query()->where('application_id', $application->id)->exists();
}

test('the candidate query filters by repository before applications are loaded', function (string $provider) {
    $application = manualLookupApplication('https://github.com/acme/app');
    $unrelated = manualLookupApplication('https://github.com/other/service');
    $similarName = manualLookupApplication('https://github.com/acme/app-legacy');
    $this->retrievedApplicationIds = collect();

    DB::enableQueryLog();
    sendManualLookupPush($this, $provider, $application->{"manual_webhook_secret_{$provider}"})->assertOk();
    $candidateQuery = collect(DB::getQueryLog())->first(fn (array $query): bool => str_contains($query['query'], 'from "applications" where "git_branch" = ?'));

    expect($candidateQuery['query'])->toContain('LOWER(git_repository) LIKE ?')
        ->and($candidateQuery['bindings'])->toContain('%acme/app%')
        ->and($this->retrievedApplicationIds)->not->toContain($unrelated->id)
        ->and(manualLookupDeployed($application))->toBeTrue()
        ->and(manualLookupDeployed($similarName))->toBeFalse();
})->with(['github', 'gitlab', 'bitbucket', 'gitea']);

test('every application of the pushed repository and branch is deployed', function (string $provider) {
    $applications = collect([
        'https://github.com/acme/app.git',
        'git@github.com:Acme/App.git',
        'ssh://git@git.example.com:2222/acme/app',
        'acme/app',
    ])->map(fn (string $gitRepository): Application => manualLookupApplication($gitRepository));
    $secret = $applications->first()->{"manual_webhook_secret_{$provider}"};
    $applications->each(fn (Application $application) => $application->forceFill(["manual_webhook_secret_{$provider}" => $secret])->save());

    sendManualLookupPush($this, $provider, $secret)->assertOk();

    $applications->each(fn (Application $application) => expect(manualLookupDeployed($application))->toBeTrue());
})->with(['github', 'gitlab', 'bitbucket', 'gitea']);

test('identically named repositories on different git hosts deploy only the application whose secret signs the delivery', function () {
    $githubApplication = manualLookupApplication('https://github.com/acme/app');
    $giteaApplication = manualLookupApplication('https://gitea.example.com/acme/app.git');

    sendManualLookupPush($this, 'gitea', $giteaApplication->manual_webhook_secret_gitea)->assertOk();

    expect(manualLookupDeployed($giteaApplication))->toBeTrue()
        ->and(manualLookupDeployed($githubApplication))->toBeFalse();
});

test('applications of one repository deploy only when the delivery branch and secret are theirs', function (string $provider) {
    $staging = manualLookupApplication('https://gitlab.com/acme/app.git', 'main');
    $production = manualLookupApplication('https://gitlab.com/acme/app.git', 'production');
    $productionSecret = $production->{"manual_webhook_secret_{$provider}"};

    // A push to the default branch (what GitLab's "Test" button sends) signed with
    // the production secret matches only staging, so it deploys nothing.
    sendManualLookupPush($this, $provider, $productionSecret, branch: 'main')
        ->assertOk()
        ->assertExactJson([['status' => 'failed', 'message' => 'No matching application or invalid signature.']]);
    expect(manualLookupDeployed($staging))->toBeFalse()
        ->and(manualLookupDeployed($production))->toBeFalse();

    // When both applications track the pushed branch, the other secret does not fail the delivery.
    $staging->update(['git_branch' => 'production']);
    $response = sendManualLookupPush($this, $provider, $productionSecret, branch: 'production')->assertOk();

    expect($response->json())->toHaveCount(1)
        ->and($response->json('0.status'))->toBe('success')
        ->and(manualLookupDeployed($production))->toBeTrue()
        ->and(manualLookupDeployed($staging))->toBeFalse();
})->with(['github', 'gitlab', 'bitbucket', 'gitea']);

test('an underscore in the repository name matches only an underscore', function () {
    $application = manualLookupApplication('https://github.com/acme/my_app');
    $wildcardMatch = manualLookupApplication('https://github.com/acme/myXapp');
    $this->retrievedApplicationIds = collect();

    sendManualLookupPush($this, 'github', $application->manual_webhook_secret_github, 'acme/my_app')->assertOk();

    expect(manualLookupDeployed($application))->toBeTrue()
        ->and($this->retrievedApplicationIds)->not->toContain($wildcardMatch->id);
});

test('pushes that must not deploy an application', function (string $case, string $expectedMessage) {
    $application = manualLookupApplication('https://github.com/acme/app', $case === 'other branch' ? 'develop' : 'main');
    $secret = $application->manual_webhook_secret_github;
    match ($case) {
        'deleted application' => DB::table('applications')->where('id', $application->id)->update(['deleted_at' => now()]),
        'auto deploy disabled' => $application->settings->update(['is_auto_deploy_enabled' => false]),
        'invalid signature' => $secret = 'wrong-secret',
        default => null,
    };

    $response = sendManualLookupPush($this, 'github', $secret)->assertOk();

    expect($response->getContent())->toContain($expectedMessage)
        ->and(manualLookupDeployed($application))->toBeFalse();
})->with([
    'other branch' => ['other branch', 'No matching application or invalid signature.'],
    'deleted application' => ['deleted application', 'No matching application or invalid signature.'],
    'auto deploy disabled' => ['auto deploy disabled', 'Deployments disabled.'],
    'invalid signature' => ['invalid signature', 'No matching application or invalid signature.'],
]);

test('pull request events dispatch only applications of the repository', function (string $action) {
    $application = manualLookupApplication('https://github.com/acme/app');
    $application->settings->update(['is_preview_deployments_enabled' => true]);
    $unrelated = manualLookupApplication('https://github.com/other/service');
    $this->retrievedApplicationIds = collect();

    sendManualLookupGithubPullRequest($this, $application, $action)->assertOk();

    Queue::assertPushed(ProcessGithubPullRequestWebhook::class, 1);
    Queue::assertPushed(ProcessGithubPullRequestWebhook::class, fn (ProcessGithubPullRequestWebhook $job): bool => $job->applicationId === $application->id && $job->action === $action);
    expect($this->retrievedApplicationIds)->not->toContain($unrelated->id);
})->with(['opened', 'closed']);

test('a closed GitLab merge request loads only applications of the repository', function () {
    Process::fake();
    $application = manualLookupApplication('git@gitlab.example.com:acme/app.git', 'feature/parent');
    $preview = ApplicationPreview::create([
        'application_id' => $application->id,
        'pull_request_id' => 42,
        'pull_request_html_url' => 'https://gitlab.example.com/acme/app/-/merge_requests/42',
        'git_type' => 'gitlab',
    ]);
    $unrelated = manualLookupApplication('https://gitlab.example.com/other/service');
    $this->retrievedApplicationIds = collect();

    $payload = json_encode([
        'object_kind' => 'merge_request',
        'project' => ['id' => 987654321, 'path_with_namespace' => 'acme/app'],
        'object_attributes' => [
            'action' => 'close',
            'iid' => 42,
            'url' => 'https://gitlab.example.com/acme/app/-/merge_requests/42',
            'title' => 'Change',
            'source_branch' => 'feature/child',
            'target_branch' => 'main',
            'source_project_id' => 987654321,
            'target_project_id' => 987654321,
            'last_commit' => ['id' => 'abc1234def5678', 'message' => 'Change'],
        ],
    ], JSON_THROW_ON_ERROR);

    $response = $this->call('POST', '/webhooks/source/gitlab/events/manual', [], [], [], [
        'HTTP_X-Gitlab-Event' => 'Merge Request Hook',
        'HTTP_X-Gitlab-Token' => $application->manual_webhook_secret_gitlab,
        'CONTENT_TYPE' => 'application/json',
    ], $payload);

    $response->assertOk()->assertJson([['status' => 'success', 'message' => 'Preview deployment closed.']]);
    Queue::assertPushed(DeleteResourceJob::class, fn (DeleteResourceJob $job): bool => $job->resource->is($preview));
    expect($this->retrievedApplicationIds)->not->toContain($unrelated->id);
});
