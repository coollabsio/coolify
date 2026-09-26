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
        'name' => 'github-app-update-api-test-'.Str::random(6),
        'token' => hash('sha256', $plainTextToken),
        'abilities' => ['*'],
        'team_id' => $this->team->id,
    ]);
    $this->bearerToken = $token->getKey().'|'.$plainTextToken;

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    $this->githubAppOrgA = GithubApp::create([
        'name' => 'Org A App',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'custom_user' => 'git',
        'custom_port' => 22,
        'team_id' => $this->team->id,
        'is_system_wide' => false,
    ]);

    $this->githubAppOrgB = GithubApp::create([
        'name' => 'Org B App',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'custom_user' => 'git',
        'custom_port' => 22,
        'team_id' => $this->team->id,
        'is_system_wide' => false,
    ]);
});

function applicationGithubAppUpdateHeaders(string $bearerToken): array
{
    return [
        'Authorization' => 'Bearer '.$bearerToken,
        'Content-Type' => 'application/json',
    ];
}

describe('PATCH /api/v1/applications/{uuid} github_app_uuid', function () {
    test('updates application to a new GitHub App source belonging to the team', function () {
        $this->application->update([
            'source_id' => $this->githubAppOrgA->id,
            'source_type' => $this->githubAppOrgA->getMorphClass(),
            'repository_project_id' => 12345,
        ]);

        $response = $this->withHeaders(applicationGithubAppUpdateHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$this->application->uuid}", [
                'github_app_uuid' => $this->githubAppOrgB->uuid,
                'git_repository' => 'https://github.com/organization-b/repo',
                'git_branch' => 'main',
            ]);

        $response->assertOk();

        $fresh = $this->application->fresh();
        expect($fresh->source_id)->toBe($this->githubAppOrgB->id);
        expect($fresh->source_type)->toBe($this->githubAppOrgB->getMorphClass());
        expect($fresh->git_repository)->toBe('https://github.com/organization-b/repo');
        expect($fresh->git_branch)->toBe('main');
        expect($fresh->repository_project_id)->toBeNull();
    });

    test('updates application to a system-wide GitHub App source', function () {
        $otherTeam = Team::factory()->create();
        $systemWideApp = GithubApp::create([
            'name' => 'System Wide GitHub App',
            'api_url' => 'https://api.github.com',
            'html_url' => 'https://github.com',
            'custom_user' => 'git',
            'custom_port' => 22,
            'team_id' => $otherTeam->id,
            'is_system_wide' => true,
        ]);

        $response = $this->withHeaders(applicationGithubAppUpdateHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$this->application->uuid}", [
                'github_app_uuid' => $systemWideApp->uuid,
            ]);

        $response->assertOk();

        $fresh = $this->application->fresh();
        expect($fresh->source_id)->toBe($systemWideApp->id);
        expect($fresh->source_type)->toBe($systemWideApp->getMorphClass());
    });

    test('clears GitHub App source when github_app_uuid is null', function () {
        $this->application->update([
            'source_id' => $this->githubAppOrgA->id,
            'source_type' => $this->githubAppOrgA->getMorphClass(),
            'repository_project_id' => 12345,
        ]);

        $response = $this->withHeaders(applicationGithubAppUpdateHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$this->application->uuid}", [
                'github_app_uuid' => null,
            ]);

        $response->assertOk();

        $fresh = $this->application->fresh();
        expect($fresh->source_id)->toBeNull();
        expect($fresh->source_type)->toBeNull();
        expect($fresh->repository_project_id)->toBeNull();
    });

    test('rejects non-existent github_app_uuid with 404', function () {
        $response = $this->withHeaders(applicationGithubAppUpdateHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$this->application->uuid}", [
                'github_app_uuid' => 'non-existent-uuid',
            ]);

        $response->assertStatus(404);
        $response->assertJson(['message' => 'Github App not found.']);
    });

    test('rejects another team private github_app_uuid with 404', function () {
        $otherTeam = Team::factory()->create();
        $otherTeamApp = GithubApp::create([
            'name' => 'Other Team App',
            'api_url' => 'https://api.github.com',
            'html_url' => 'https://github.com',
            'custom_user' => 'git',
            'custom_port' => 22,
            'team_id' => $otherTeam->id,
            'is_system_wide' => false,
        ]);

        $response = $this->withHeaders(applicationGithubAppUpdateHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$this->application->uuid}", [
                'github_app_uuid' => $otherTeamApp->uuid,
            ]);

        $response->assertStatus(404);
        $response->assertJson(['message' => 'Github App not found.']);
    });

    test('rejects non-string github_app_uuid with 422 validation error', function () {
        $response = $this->withHeaders(applicationGithubAppUpdateHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$this->application->uuid}", [
                'github_app_uuid' => ['invalid' => 'array'],
            ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('github_app_uuid');
    });
});
