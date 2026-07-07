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
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $plainTextToken = Str::random(40);
    $token = $this->user->tokens()->create([
        'name' => 'public-git-api-test-'.Str::random(6),
        'token' => hash('sha256', $plainTextToken),
        'abilities' => ['*'],
        'team_id' => $this->team->id,
    ]);
    $this->bearerToken = $token->getKey().'|'.$plainTextToken;

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
});

function createPublicGitApplication(array $overrides = []): Application
{
    $response = test()->withHeaders([
        'Authorization' => 'Bearer '.test()->bearerToken,
        'Content-Type' => 'application/json',
    ])->postJson('/api/v1/applications/public', array_merge([
        'project_uuid' => test()->project->uuid,
        'environment_uuid' => test()->environment->uuid,
        'server_uuid' => test()->server->uuid,
        'git_repository' => 'https://gitlab.com/coolify/test-static-app',
        'git_branch' => 'main',
        'build_pack' => 'static',
        'ports_exposes' => '80',
        'autogenerate_domain' => false,
    ], $overrides));

    $response->assertCreated();

    return Application::where('uuid', $response->json('uuid'))->firstOrFail();
}

test('public non github repositories keep their full git repository url', function () {
    $application = createPublicGitApplication([
        'git_repository' => 'https://cnb.cool/matridx/frp_webui',
    ]);

    expect($application->git_repository)->toBe('https://cnb.cool/matridx/frp_webui')
        ->and($application->source_type)->toBeNull()
        ->and($application->source_id)->toBeNull();
});

test('public github repositories are stored as owner and repository with the public github source', function () {
    GithubApp::unguarded(fn () => GithubApp::create([
        'id' => 0,
        'name' => 'Public GitHub',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'is_public' => true,
        'team_id' => 0,
    ]));

    $application = createPublicGitApplication([
        'git_repository' => 'https://github.com/coollabsio/coolify-examples',
    ]);

    expect($application->git_repository)->toBe('coollabsio/coolify-examples')
        ->and($application->source_type)->toBe(GithubApp::class)
        ->and($application->source_id)->toBe(0);
});
