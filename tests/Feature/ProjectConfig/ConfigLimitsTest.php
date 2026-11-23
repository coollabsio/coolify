<?php

use App\Jobs\PlanEnvironmentConfigJob;
use App\Models\Application;
use App\Models\EnvironmentConfigLink;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\PrivateKey;
use App\Models\S3Storage;
use App\Models\StandalonePostgresql;
use App\Models\User;
use App\Services\ProjectConfig\ConfigApplier;
use App\Services\ProjectConfig\NamedCredentials;
use App\Services\ProjectConfig\RepositoryFile;
use App\Services\ProjectConfig\RepositoryFileReader;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->fx = projectConfigFixture();
    $this->token = $this->fx['user']->createToken('all', ['*'], teamId: $this->fx['team']->id)->plainTextToken;
});

function limitsFile(array $resources): void
{
    app()->instance(RepositoryFileReader::class, new class($resources) extends RepositoryFileReader
    {
        public function __construct(private array $resources) {}

        public function read(EnvironmentConfigLink $link, ?string $commit = null): RepositoryFile
        {
            return new RepositoryFile(json_encode(['version' => 1, 'resources' => $this->resources]), str_repeat('c', 40), false);
        }
    });
}

function limitsApi(string $uri, array $body, ?string $token = null)
{
    app('auth')->forgetGuards();

    return test()->withHeaders(['Authorization' => 'Bearer '.($token ?? test()->token), 'Accept' => 'application/json'])
        ->json('POST', "/api/v1{$uri}", $body);
}

function limitsBody(array $overrides = []): array
{
    return [
        'repository' => 'https://github.com/acme/web', 'branch' => 'main', 'source_type' => 'public',
        'server_uuid' => test()->fx['server']->uuid, ...$overrides,
    ];
}

function limitsS3(string $name, int $teamId): S3Storage
{
    return S3Storage::forceCreate(['name' => $name, 'team_id' => $teamId, 'region' => 'x', 'key' => 'k', 'secret' => 's', 'bucket' => 'b', 'endpoint' => 'https://s3.example.com']);
}

it('plans a template through the API and creates normal resources', function () {
    limitsFile([
        'web' => ['type' => 'application', 'build' => ['pack' => 'dockerfile'], 'ports' => ['exposes' => '3000']],
        'db' => ['type' => 'postgresql'],
    ]);
    $uri = "/environments/{$this->fx['environment']->uuid}/config/template";

    $plan = limitsApi("{$uri}/plan", limitsBody())->assertOk();
    expect($plan->json('commit'))->toBe(str_repeat('c', 40))
        ->and(collect($plan->json('changes'))->where('kind', 'create')->pluck('key')->sort()->values()->all())->toBe(['db', 'web']);

    $created = limitsApi($uri, limitsBody(['commit' => str_repeat('c', 40)]))->assertCreated()->json('resources');

    expect(collect($created)->pluck('type')->sort()->values()->all())->toBe(['application', 'postgresql']);
    $app = Application::where('environment_id', $this->fx['environment']->id)->sole();
    expect($app->config_key)->toBeNull()
        ->and($app->git_repository)->toBe('https://github.com/acme/web')
        ->and(StandalonePostgresql::where('environment_id', $this->fx['environment']->id)->sole()->config_key)->toBeNull()
        ->and(EnvironmentConfigLink::count())->toBe(0);
});

it('validates the template request', function () {
    limitsApi("/environments/{$this->fx['environment']->uuid}/config/template", limitsBody(['commit' => 'main', 'file_path' => '../x']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['commit', 'file_path']);
});

it('refuses a template in an environment that follows coolify.json', function () {
    limitsFile(['db' => ['type' => 'postgresql']]);
    EnvironmentConfigLink::factory()->create(['environment_id' => $this->fx['environment']->id]);

    limitsApi("/environments/{$this->fx['environment']->uuid}/config/template", limitsBody())
        ->assertUnprocessable()
        ->assertJsonPath('message', 'This environment follows coolify.json. Add the resources to its file instead.');
    expect(StandalonePostgresql::count())->toBe(0);
});

it('does not return a database error as the message of a failed template', function () {
    limitsFile(['db' => ['type' => 'postgresql']]);
    $this->mock(ConfigApplier::class)->shouldReceive('createFromTemplate')
        ->andThrow(new QueryException('pgsql', 'insert into "secrets"', [], new Exception('broken')));

    limitsApi("/environments/{$this->fx['environment']->uuid}/config/template", limitsBody())->assertServerError();
});

it('needs team-wide write to use a team credential in a template', function () {
    limitsFile(['db' => ['type' => 'postgresql']]);
    $key = PrivateKey::factory()->create(['team_id' => $this->fx['team']->id]);
    $member = User::factory()->create();
    $this->fx['team']->members()->attach($member->id, ['role' => 'member']);
    $token = $member->createToken('m', ['*'], teamId: $this->fx['team']->id)->plainTextToken;

    limitsApi("/environments/{$this->fx['environment']->uuid}/config/template/plan", limitsBody(['source_type' => 'deploy_key', 'source_uuid' => $key->uuid]), $token)
        ->assertForbidden();
});

it('does not find the environment of another team', function () {
    limitsFile(['db' => ['type' => 'postgresql']]);
    $other = projectConfigFixture();

    limitsApi("/environments/{$other['environment']->uuid}/config/template/plan", limitsBody())->assertNotFound();
});

it('selects a named credential only when one matches', function () {
    $teamId = $this->fx['team']->id;
    $other = projectConfigFixture();

    expect(NamedCredentials::error('s3_storage', 'backups', $teamId))->toBe('No S3 storage named "backups" is available to this team.');

    limitsS3('backups', $other['team']->id);
    expect(NamedCredentials::find('s3_storage', 'backups', $teamId))->toBeNull();

    $own = limitsS3('backups', $teamId);
    expect(NamedCredentials::find('s3_storage', 'backups', $teamId)?->is($own))->toBeTrue();

    limitsS3('backups', $teamId);
    expect(NamedCredentials::find('s3_storage', 'backups', $teamId))->toBeNull()
        ->and(NamedCredentials::error('s3_storage', 'backups', $teamId))->toBe('2 S3 storages are named "backups". Give one of them another name in Coolify.');
});

it('prefers the git app of the team over a system-wide app with the same name', function () {
    $teamId = $this->fx['team']->id;
    $other = projectConfigFixture();
    $attributes = ['api_url' => 'https://api.github.com', 'html_url' => 'https://github.com', 'is_public' => false];
    GithubApp::create(['name' => 'shared', 'team_id' => $other['team']->id, 'is_system_wide' => true, 'app_id' => 1, ...$attributes]);

    expect(NamedCredentials::find('github_app', 'shared', $teamId))->not->toBeNull();

    $own = GithubApp::create(['name' => 'shared', 'team_id' => $teamId, 'app_id' => 2, ...$attributes]);
    expect(NamedCredentials::find('github_app', 'shared', $teamId)?->is($own))->toBeTrue();
});

it('plans a push from a GitLab app webhook for the link of that app', function () {
    Queue::fake();
    $gitlab = GitlabApp::create([
        'name' => 'acme-gitlab', 'api_url' => 'https://gitlab.com/api/v4', 'html_url' => 'https://gitlab.com',
        'is_public' => false, 'access_token' => 'token', 'webhook_token' => 'hook-token', 'team_id' => $this->fx['team']->id,
    ]);
    $link = EnvironmentConfigLink::factory()->create([
        'environment_id' => $this->fx['environment']->id, 'mode' => 'git',
        'source_type' => GitlabApp::class, 'source_id' => $gitlab->id,
        'repository' => 'acme/api', 'branch' => 'main',
    ]);
    $payload = json_encode([
        'object_kind' => 'push', 'ref' => 'refs/heads/main', 'before' => str_repeat('1', 40), 'after' => str_repeat('2', 40),
        'project' => ['id' => 55, 'path_with_namespace' => 'acme/api'],
        'commits' => [['message' => 'Update', 'added' => [], 'removed' => [], 'modified' => ['coolify.json']]],
    ]);
    $push = fn (string $token) => test()->call('POST', '/webhooks/source/gitlab/events', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_X_GITLAB_EVENT' => 'Push Hook', 'HTTP_X_GITLAB_TOKEN' => $token,
    ], $payload);

    $push('wrong-token')->assertUnauthorized();
    Queue::assertNotPushed(PlanEnvironmentConfigJob::class);

    $push('hook-token')->assertOk();
    Queue::assertPushed(PlanEnvironmentConfigJob::class, fn ($job) => $job->link->is($link) && $job->commit === str_repeat('2', 40));
});

it('removes credentials from git error messages', function () {
    expect(RepositoryFileReader::withoutCredentials("fatal: unable to access 'https://x-access-token:ghs_secret@github.com/acme/api.git/': 403"))
        ->toBe("fatal: unable to access 'https://***@github.com/acme/api.git/': 403")
        ->and(RepositoryFileReader::withoutCredentials('ssh://git@host:22/acme/api and https://oauth2:glpat@gitlab.com/x'))
        ->toBe('ssh://***@host:22/acme/api and https://***@gitlab.com/x')
        ->and(RepositoryFileReader::withoutCredentials('git@github.com:acme/api.git'))->toBe('git@github.com:acme/api.git');
});
