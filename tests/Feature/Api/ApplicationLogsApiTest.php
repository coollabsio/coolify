<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('ssh-keys');
    config([
        'app.maintenance.driver' => 'file',
        'cache.default' => 'array',
        'constants.ssh.mux_enabled' => false,
    ]);

    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $this->bearerToken = $this->user->createToken('application-logs-api-test', ['read'])->plainTextToken;
    $this->privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
    ]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
});

function applicationLogsApiHeaders(string $bearerToken): array
{
    return ['Authorization' => 'Bearer '.$bearerToken];
}

test('returns logs from an existing stopped application container', function () {
    $container = json_encode([
        'ID' => 'stopped-container-id',
        'Names' => 'stopped-container-name',
        'Labels' => "coolify.applicationId={$this->application->id},coolify.pullRequestId=0",
        'State' => 'exited',
        'Status' => 'Exited (0) 5 minutes ago',
    ], JSON_THROW_ON_ERROR);

    Process::fake([
        '*docker ps -a*' => Process::result(output: $container),
        '*docker logs -n 100*' => Process::result(output: 'stopped container output'),
    ]);

    $this->withHeaders(applicationLogsApiHeaders($this->bearerToken))
        ->getJson("/api/v1/applications/{$this->application->uuid}/logs")
        ->assertOk()
        ->assertExactJson(['logs' => 'stopped container output']);

    Process::assertRan(fn ($process) => str_contains($process->command, 'docker logs -n 100'));
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'docker inspect'));
});

test('returns the existing not running response when no application container exists', function () {
    Process::fake([
        '*docker ps -a*' => Process::result(output: ''),
    ]);

    $this->withHeaders(applicationLogsApiHeaders($this->bearerToken))
        ->getJson("/api/v1/applications/{$this->application->uuid}/logs")
        ->assertBadRequest()
        ->assertExactJson(['message' => 'Application is not running.']);

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'docker logs'));
});

test('does not expose application logs across teams', function () {
    $otherTeam = Team::factory()->create();
    $otherProject = Project::factory()->create(['team_id' => $otherTeam->id]);
    $otherEnvironment = Environment::factory()->create(['project_id' => $otherProject->id]);
    $otherApplication = Application::factory()->create([
        'environment_id' => $otherEnvironment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    Process::fake();

    $this->withHeaders(applicationLogsApiHeaders($this->bearerToken))
        ->getJson("/api/v1/applications/{$otherApplication->uuid}/logs")
        ->assertNotFound()
        ->assertExactJson(['message' => 'Application not found.']);

    Process::assertNothingRan();
});
