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
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));

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
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
});

function sourceCommitSettingApiHeaders(string $bearerToken): array
{
    return [
        'Authorization' => 'Bearer '.$bearerToken,
        'Content-Type' => 'application/json',
    ];
}

describe('PATCH /api/v1/applications/{uuid} include_source_commit_in_build', function () {
    test('updates the application setting through the API', function () {
        expect((bool) $this->application->settings->include_source_commit_in_build)->toBeFalse();

        $this->withHeaders(sourceCommitSettingApiHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$this->application->uuid}", [
                'include_source_commit_in_build' => true,
            ])
            ->assertOk();

        expect((bool) $this->application->fresh()->settings->include_source_commit_in_build)->toBeTrue();

        $this->withHeaders(sourceCommitSettingApiHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$this->application->uuid}", [
                'include_source_commit_in_build' => false,
            ])
            ->assertOk();

        expect((bool) $this->application->fresh()->settings->include_source_commit_in_build)->toBeFalse();
    });

    test('rejects non boolean values', function () {
        $this->withHeaders(sourceCommitSettingApiHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$this->application->uuid}", [
                'include_source_commit_in_build' => 'not-a-boolean',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('include_source_commit_in_build');
    });
});
