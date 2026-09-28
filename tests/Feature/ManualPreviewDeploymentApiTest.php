<?php

use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    InstanceSettings::unguarded(fn () => InstanceSettings::query()->updateOrCreate(
        ['id' => 0],
        ['is_api_enabled' => true],
    ));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $plainTextToken = Str::random(40);
    $token = $this->user->tokens()->create([
        'name' => 'manual-preview-deployment-test',
        'token' => hash('sha256', $plainTextToken),
        'abilities' => ['*'],
        'team_id' => $this->team->id,
    ]);
    $this->bearerToken = $token->getKey().'|'.$plainTextToken;

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::factory()->create([
        'server_id' => $this->server->id,
        'network' => 'coolify-'.Str::lower(Str::random(8)),
    ]);
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->githubApp = GithubApp::create([
        'name' => 'manual-preview-github-app',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'is_public' => true,
        'team_id' => $this->team->id,
    ]);
});

it('creates and queues a missing GitHub preview when explicitly deployed through the API', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'source_id' => $this->githubApp->id,
        'source_type' => $this->githubApp->getMorphClass(),
        'git_repository' => 'example/repository',
        'git_branch' => 'main',
        'git_commit_sha' => 'HEAD',
        'build_pack' => 'dockerfile',
        'fqdn' => 'https://example.com',
    ]);

    $response = $this->withToken($this->bearerToken)->postJson('/api/v1/deploy', [
        'uuid' => $application->uuid,
        'pull_request_id' => 66,
    ]);

    $response->assertSuccessful()
        ->assertJsonPath('deployments.0.resource_uuid', $application->uuid)
        ->assertJsonPath('deployments.0.message', "Application {$application->name} deployment queued.");

    $preview = ApplicationPreview::query()
        ->where('application_id', $application->id)
        ->where('pull_request_id', 66)
        ->firstOrFail();

    expect($preview->git_type)->toBe('github')
        ->and($preview->pull_request_html_url)->toBe('https://github.com/example/repository/pull/66')
        ->and($preview->fqdn)->toBe('https://66.example.com');

    $deployment = $application->deployment_queue()->latest('id')->firstOrFail();

    expect($deployment->pull_request_id)->toBe(66)
        ->and($deployment->git_type)->toBe('github');
});

it('uses the provider type from an existing preview when queueing through the API', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'git_repository' => 'https://git.example.com/example/repository.git',
        'git_branch' => 'main',
        'git_commit_sha' => 'HEAD',
        'build_pack' => 'dockerfile',
    ]);
    ApplicationPreview::create([
        'application_id' => $application->id,
        'pull_request_id' => 41,
        'pull_request_html_url' => 'https://git.example.com/example/repository/pulls/41',
        'git_type' => 'gitea',
    ]);

    $response = $this->withToken($this->bearerToken)->postJson('/api/v1/deploy', [
        'uuid' => $application->uuid,
        'pull_request_id' => 41,
    ]);

    $response->assertSuccessful();

    $deployment = $application->deployment_queue()->latest('id')->firstOrFail();

    expect($deployment->pull_request_id)->toBe(41)
        ->and($deployment->git_type)->toBe('gitea');
});

it('does not invent provider metadata for an unconfigured Git repository', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'git_repository' => 'https://git.example.com/example/repository.git',
        'git_branch' => 'main',
        'git_commit_sha' => 'HEAD',
        'build_pack' => 'dockerfile',
    ]);

    $response = $this->withToken($this->bearerToken)->postJson('/api/v1/deploy', [
        'uuid' => $application->uuid,
        'pull_request_id' => 66,
    ]);

    $response->assertSuccessful()
        ->assertJsonPath('deployments.0.message', 'Pull request 66 not found for this resource.');

    expect($application->previews()->exists())->toBeFalse()
        ->and($application->deployment_queue()->exists())->toBeFalse();
});

it('does not create a preview before deployment authorization succeeds', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'source_id' => $this->githubApp->id,
        'source_type' => $this->githubApp->getMorphClass(),
        'git_repository' => 'example/repository',
        'git_branch' => 'main',
        'git_commit_sha' => 'HEAD',
        'build_pack' => 'dockerfile',
    ]);
    $this->team->members()->updateExistingPivot($this->user->id, ['role' => 'member']);

    $response = $this->withToken($this->bearerToken)->postJson('/api/v1/deploy', [
        'uuid' => $application->uuid,
        'pull_request_id' => 66,
    ]);

    $response->assertForbidden();

    expect($application->previews()->exists())->toBeFalse()
        ->and($application->deployment_queue()->exists())->toBeFalse();
});

it('keeps the stored preview domain when the deployment queue rejects the request', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'source_id' => $this->githubApp->id,
        'source_type' => $this->githubApp->getMorphClass(),
        'git_repository' => 'example/repository',
        'git_branch' => 'main',
        'git_commit_sha' => 'HEAD',
        'build_pack' => 'dockerfile',
        'fqdn' => 'https://example.com',
        'preview_url_template' => '{{random}}.{{domain}}',
    ]);
    $preview = ApplicationPreview::create([
        'application_id' => $application->id,
        'pull_request_id' => 66,
        'pull_request_html_url' => 'https://github.com/example/repository/pull/66',
        'git_type' => 'github',
        'fqdn' => 'https://stable.example.com',
    ]);
    $this->server->settings->update(['deployment_queue_limit' => 0]);

    $response = $this->withToken($this->bearerToken)->postJson('/api/v1/deploy', [
        'uuid' => $application->uuid,
        'pull_request_id' => 66,
    ]);

    $response->assertStatus(429);

    expect($preview->refresh()->fqdn)->toBe('https://stable.example.com')
        ->and($application->deployment_queue()->exists())->toBeFalse();
});

it('keeps the stored preview domain when a duplicate deployment is skipped', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'source_id' => $this->githubApp->id,
        'source_type' => $this->githubApp->getMorphClass(),
        'git_repository' => 'example/repository',
        'git_branch' => 'main',
        'git_commit_sha' => 'HEAD',
        'build_pack' => 'dockerfile',
        'fqdn' => 'https://example.com',
        'preview_url_template' => '{{random}}.{{domain}}',
    ]);

    $this->withToken($this->bearerToken)->postJson('/api/v1/deploy', [
        'uuid' => $application->uuid,
        'pull_request_id' => 66,
    ])->assertSuccessful();

    $preview = $application->previews()->where('pull_request_id', 66)->firstOrFail();
    $generatedFqdn = $preview->fqdn;

    expect($generatedFqdn)->toStartWith('https://')
        ->and($generatedFqdn)->toEndWith('.example.com');

    $this->withToken($this->bearerToken)->postJson('/api/v1/deploy', [
        'uuid' => $application->uuid,
        'pull_request_id' => 66,
    ])->assertSuccessful()
        ->assertJsonPath('deployments.0.message', 'Deployment already queued for this commit.');

    expect($preview->refresh()->fqdn)->toBe($generatedFqdn)
        ->and($application->deployment_queue()->count())->toBe(1);
});

it('does not expose a domainless application through a generated preview domain', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'source_id' => $this->githubApp->id,
        'source_type' => $this->githubApp->getMorphClass(),
        'git_repository' => 'example/repository',
        'git_branch' => 'main',
        'git_commit_sha' => 'HEAD',
        'build_pack' => 'dockerfile',
        'fqdn' => null,
    ]);

    $this->withToken($this->bearerToken)->postJson('/api/v1/deploy', [
        'uuid' => $application->uuid,
        'pull_request_id' => 66,
    ])->assertSuccessful();

    $preview = $application->previews()->where('pull_request_id', 66)->firstOrFail();

    expect($preview->fqdn)->toBeNull();
});
