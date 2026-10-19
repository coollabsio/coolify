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

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);
    $this->token = $this->user->createToken('dockerfile-update-test', ['*'])->plainTextToken;

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => 'dockerfile',
        'dockerfile' => "FROM nginx:alpine\n",
        'custom_healthcheck_found' => true,
        'health_check_interval' => 30,
    ]);
});

function patchApplicationDockerfile(array $payload)
{
    return test()->withHeaders(['Authorization' => 'Bearer '.test()->token])
        ->patchJson('/api/v1/applications/'.test()->application->uuid, $payload);
}

test('PATCH updates the Dockerfile of a Dockerfile application', function () {
    $dockerfile = "FROM nginx:1.27-alpine\n";

    patchApplicationDockerfile(['dockerfile' => base64_encode($dockerfile)])->assertOk();

    $application = $this->application->refresh();
    expect($application->dockerfile)->toBe($dockerfile)
        ->and($application->custom_healthcheck_found)->toBeFalsy()
        ->and($application->health_check_interval)->toBe(5);
});

test('PATCH rejects a Dockerfile that is not base64 encoded', function () {
    patchApplicationDockerfile(['dockerfile' => "FROM nginx:1.27-alpine\n"])
        ->assertUnprocessable()
        ->assertJsonPath('errors.dockerfile', 'The dockerfile should be base64 encoded.');

    expect($this->application->refresh()->dockerfile)->toBe("FROM nginx:alpine\n");
});

test('PATCH rejects a Dockerfile for applications built from a git repository', function () {
    $this->application->forceFill(['dockerfile' => null])->saveQuietly();

    patchApplicationDockerfile(['dockerfile' => base64_encode("FROM nginx:1.27-alpine\n")])
        ->assertUnprocessable()
        ->assertInvalid(['dockerfile']);

    expect($this->application->refresh()->dockerfile)->toBeNull();
});
