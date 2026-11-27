<?php

use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    $this->user = User::factory()->create();
    $this->sessionTeam = Team::factory()->create();
    $this->resourceTeam = Team::factory()->create();
    $this->sessionTeam->members()->attach($this->user->id, ['role' => 'owner']);
    $this->resourceTeam->members()->attach($this->user->id, ['role' => 'member']);

    $this->project = Project::factory()->create(['team_id' => $this->resourceTeam->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->server = Server::factory()->create(['team_id' => $this->resourceTeam->id]);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->sessionTeam]);
});

test('a direct link to a project of another own team switches to that team', function () {
    $this->get(route('project.resource.index', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ]))->assertSuccessful();

    expect(data_get(session('currentTeam'), 'id'))->toBe($this->resourceTeam->id)
        ->and($this->user->fresh()->current_team_id)->toBe($this->resourceTeam->id);
});

test('a direct link to a server of another own team switches to that team', function () {
    $this->get(route('server.show', ['server_uuid' => $this->server->uuid]))->assertSuccessful();

    expect(data_get(session('currentTeam'), 'id'))->toBe($this->resourceTeam->id);
});

test('a direct link to a resource of a team the user is not in stays a 404', function () {
    $foreignTeam = Team::factory()->create();
    $foreignProject = Project::factory()->create(['team_id' => $foreignTeam->id]);
    $foreignEnvironment = Environment::factory()->create(['project_id' => $foreignProject->id]);

    $this->get(route('project.resource.index', [
        'project_uuid' => $foreignProject->uuid,
        'environment_uuid' => $foreignEnvironment->uuid,
    ]))->assertNotFound();

    expect(data_get(session('currentTeam'), 'id'))->toBe($this->sessionTeam->id);
});

test('the two-factor requirement of the resource team applies after the switch', function () {
    $this->resourceTeam->updateQuietly(['is_two_factor_required' => true]);

    $this->get(route('server.show', ['server_uuid' => $this->server->uuid]))
        ->assertRedirect(route('two-factor.required'));
});

function resourceTeamGithubApp(Team $team, bool $isSystemWide): GithubApp
{
    return GithubApp::create([
        'name' => 'github-app',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'app_id' => 1234567890,
        'webhook_secret' => 'test-secret',
        'is_public' => false,
        'is_system_wide' => $isSystemWide,
        'team_id' => $team->id,
    ]);
}

test('a direct link to a destination of another own team switches to the team of its server', function () {
    $destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();

    $this->get(route('destination.show', ['destination_uuid' => $destination->uuid]));

    expect(data_get(session('currentTeam'), 'id'))->toBe($this->resourceTeam->id);
});

test('a direct link to an S3 storage of another own team switches to that team', function () {
    $storage = S3Storage::create([
        'name' => 'backups', 'region' => 'us-east-1', 'key' => 'key', 'secret' => 'secret',
        'bucket' => 'backups', 'endpoint' => 'https://1.1.1.1', 'team_id' => $this->resourceTeam->id,
    ]);

    $this->get(route('storage.show', ['storage_uuid' => $storage->uuid]));

    expect(data_get(session('currentTeam'), 'id'))->toBe($this->resourceTeam->id);
});

test('a direct link to a git source of another own team switches to that team', function () {
    $githubApp = resourceTeamGithubApp($this->resourceTeam, isSystemWide: false);

    $this->get(route('source.github.show', ['github_app_uuid' => $githubApp->uuid]));

    expect(data_get(session('currentTeam'), 'id'))->toBe($this->resourceTeam->id);
});

test('a direct link to a system-wide git source keeps the session team', function () {
    $githubApp = resourceTeamGithubApp($this->resourceTeam, isSystemWide: true);

    $this->get(route('source.github.show', ['github_app_uuid' => $githubApp->uuid]));

    expect(data_get(session('currentTeam'), 'id'))->toBe($this->sessionTeam->id);
});
