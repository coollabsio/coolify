<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);
    $this->token = $this->user->createToken('github-app-source-test', ['*'])->plainTextToken;

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $this->oldGithubApp = githubAppSourceApiApp($this->team->id, 'org-a');
    $this->newGithubApp = githubAppSourceApiApp($this->team->id, 'org-b');

    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'git_repository' => 'org-a/repository',
        'source_id' => $this->oldGithubApp->id,
        'source_type' => GithubApp::class,
        'repository_project_id' => 111,
        'private_key_id' => null,
    ]);
});

function githubAppSourceApiApp(int $teamId, string $name, array $overrides = []): GithubApp
{
    return GithubApp::create(array_merge([
        'name' => $name,
        'team_id' => $teamId,
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'is_public' => false,
        'app_id' => 1,
        'installation_id' => 1,
    ], $overrides));
}

function patchApplicationSource(array $payload)
{
    return test()->withHeaders(['Authorization' => 'Bearer '.test()->token])
        ->patchJson('/api/v1/applications/'.test()->application->uuid, $payload);
}

test('PATCH moves the application to another GitHub App of the team', function () {
    patchApplicationSource([
        'github_app_uuid' => $this->newGithubApp->uuid,
        'git_repository' => 'org-b/repository',
        'git_branch' => 'main',
    ])->assertOk();

    $application = $this->application->refresh();
    expect($application->source_id)->toBe($this->newGithubApp->id)
        ->and($application->source_type)->toBe(GithubApp::class)
        ->and($application->git_repository)->toBe('org-b/repository')
        ->and($application->repository_project_id)->toBeNull();
});

test('PATCH rejects a GitHub App of another team', function () {
    $otherTeam = Team::factory()->create();
    $foreignGithubApp = githubAppSourceApiApp($otherTeam->id, 'foreign');

    patchApplicationSource(['github_app_uuid' => $foreignGithubApp->uuid])->assertNotFound();

    expect($this->application->refresh()->source_id)->toBe($this->oldGithubApp->id);
});

test('PATCH rejects a GitHub App that is not installed', function () {
    $notInstalled = githubAppSourceApiApp($this->team->id, 'not-installed', ['installation_id' => null]);

    patchApplicationSource(['github_app_uuid' => $notInstalled->uuid])->assertNotFound();

    expect($this->application->refresh()->source_id)->toBe($this->oldGithubApp->id);
});

test('PATCH rejects github_app_uuid for deploy key applications', function () {
    $this->application->forceFill(['private_key_id' => 1])->saveQuietly();

    patchApplicationSource(['github_app_uuid' => $this->newGithubApp->uuid])
        ->assertUnprocessable()
        ->assertInvalid(['github_app_uuid']);

    expect($this->application->refresh()->source_id)->toBe($this->oldGithubApp->id);
});

test('PATCH rejects github_app_uuid for applications without a git repository', function (array $attributes) {
    $this->application->forceFill($attributes)->saveQuietly();

    patchApplicationSource(['github_app_uuid' => $this->newGithubApp->uuid])
        ->assertUnprocessable()
        ->assertInvalid(['github_app_uuid']);

    expect($this->application->refresh()->source_id)->toBe($this->oldGithubApp->id);
})->with([
    'inline dockerfile' => [['build_pack' => 'dockerfile', 'dockerfile' => "FROM nginx:alpine\n"]],
    'docker image' => [['build_pack' => 'dockerimage']],
]);
