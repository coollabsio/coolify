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
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(
        ['id' => 0],
        ['is_api_enabled' => true],
    ));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $plainTextToken = Str::random(40);
    $token = $this->user->tokens()->create([
        'name' => 'github-app-source-api-test-'.Str::random(6),
        'token' => hash('sha256', $plainTextToken),
        'abilities' => ['*'],
        'team_id' => $this->team->id,
    ]);
    $this->bearerToken = $token->getKey().'|'.$plainTextToken;

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);

    $this->originalGithubApp = GithubApp::create([
        'name' => 'Original GitHub App',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'team_id' => $this->team->id,
        'is_public' => true,
    ]);

    $this->application = Application::factory()->create([
        'name' => 'Source switch application',
        'git_repository' => 'coollabsio/coolify',
        'git_branch' => 'main',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'source_id' => $this->originalGithubApp->id,
        'source_type' => GithubApp::class,
    ]);
});

function githubAppSourceApiHeaders(string $bearerToken): array
{
    return [
        'Authorization' => 'Bearer '.$bearerToken,
        'Content-Type' => 'application/json',
    ];
}

function createGithubAppSourceForTeam(Team $team, array $attributes = []): GithubApp
{
    return GithubApp::create(array_merge([
        'name' => 'Replacement GitHub App',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'team_id' => $team->id,
        'is_public' => true,
        'is_system_wide' => false,
    ], $attributes));
}

describe('PATCH /api/v1/applications/{uuid} github_app_uuid', function () {
    test('switches to a github app owned by the token team without changing application identity or configuration', function () {
        $replacementGithubApp = createGithubAppSourceForTeam($this->team);
        $originalUuid = $this->application->uuid;

        $this->withHeaders(githubAppSourceApiHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$this->application->uuid}", [
                'github_app_uuid' => $replacementGithubApp->uuid,
            ])
            ->assertOk();

        $application = $this->application->fresh();

        expect($application->uuid)->toBe($originalUuid)
            ->and($application->name)->toBe('Source switch application')
            ->and($application->git_repository)->toBe('coollabsio/coolify')
            ->and($application->git_branch)->toBe('main')
            ->and($application->source_id)->toBe($replacementGithubApp->id)
            ->and($application->source_type)->toBe(GithubApp::class);
    });

    test('switches to a system wide github app owned by another team', function () {
        $otherTeam = Team::factory()->create();
        $systemWideGithubApp = createGithubAppSourceForTeam($otherTeam, [
            'name' => 'System-wide GitHub App',
            'is_system_wide' => true,
        ]);

        $this->withHeaders(githubAppSourceApiHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$this->application->uuid}", [
                'github_app_uuid' => $systemWideGithubApp->uuid,
            ])
            ->assertOk();

        $application = $this->application->fresh();

        expect($application->source_id)->toBe($systemWideGithubApp->id)
            ->and($application->source_type)->toBe(GithubApp::class);
    });

    test('rejects another teams private github app without changing the existing source', function () {
        $otherTeam = Team::factory()->create();
        $inaccessibleGithubApp = createGithubAppSourceForTeam($otherTeam, [
            'name' => 'Private GitHub App from another team',
        ]);

        $this->withHeaders(githubAppSourceApiHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$this->application->uuid}", [
                'github_app_uuid' => $inaccessibleGithubApp->uuid,
            ])
            ->assertNotFound();

        $application = $this->application->fresh();

        expect($application->source_id)->toBe($this->originalGithubApp->id)
            ->and($application->source_type)->toBe(GithubApp::class);
    });
});
