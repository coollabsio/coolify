<?php

use App\Models\Application;
use App\Models\Environment;
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
    InstanceSettings::create(['id' => 0, 'is_api_enabled' => true]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    session(['currentTeam' => $this->team]);

    $plainTextToken = Str::random(40);
    $token = $this->user->tokens()->create([
        'name' => 'source-commit-api-test-'.Str::random(6),
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
});

function sourceCommitApiHeaders(string $bearerToken): array
{
    return [
        'Authorization' => 'Bearer '.$bearerToken,
        'Content-Type' => 'application/json',
    ];
}

function makeSourceCommitApplication(array $overrides = []): Application
{
    return Application::factory()->create(array_merge([
        'environment_id' => test()->environment->id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
        'build_pack' => 'nixpacks',
    ], $overrides));
}

describe('PATCH /api/v1/applications/{uuid} include_source_commit_in_build', function () {
    test('accepts include_source_commit_in_build via API', function () {
        $application = makeSourceCommitApplication();
        expect($application->settings->include_source_commit_in_build)->toBeFalse();

        $response = $this->withHeaders(sourceCommitApiHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$application->uuid}", [
                'include_source_commit_in_build' => true,
            ]);

        $response->assertOk();

        $application->refresh();
        expect($application->settings->include_source_commit_in_build)->toBeTrue();
    });

    test('can disable include_source_commit_in_build via API', function () {
        $application = makeSourceCommitApplication();
        $application->settings->include_source_commit_in_build = true;
        $application->settings->save();

        $response = $this->withHeaders(sourceCommitApiHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$application->uuid}", [
                'include_source_commit_in_build' => false,
            ]);

        $response->assertOk();

        $application->refresh();
        expect($application->settings->include_source_commit_in_build)->toBeFalse();
    });

    test('does not affect other application fields', function () {
        $application = makeSourceCommitApplication([
            'name' => 'Original Name',
            'docker_registry_image_tag' => 'v1.0.0',
        ]);

        $response = $this->withHeaders(sourceCommitApiHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$application->uuid}", [
                'include_source_commit_in_build' => true,
            ]);

        $response->assertOk();

        $application->refresh();
        expect($application->name)->toBe('Original Name')
            ->and($application->docker_registry_image_tag)->toBe('v1.0.0')
            ->and($application->settings->include_source_commit_in_build)->toBeTrue();
    });
});
