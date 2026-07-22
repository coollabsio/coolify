<?php

use App\Models\Application;
use App\Models\DockerRegistry;
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
        'name' => 'docker-registry-id-api-test-'.Str::random(6),
        'token' => hash('sha256', $plainTextToken),
        'abilities' => ['*'],
        'team_id' => $this->team->id,
    ]);
    $this->bearerToken = $token->getKey().'|'.$plainTextToken;

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);

    $this->registry = DockerRegistry::create([
        'name' => 'Test Registry',
        'registry_url' => 'ghcr.io',
        'username' => 'registry-user',
        'password' => 'registry-token',
        'team_id' => $this->team->id,
    ]);

    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
});

function dockerRegistryIdApiHeaders(string $bearerToken): array
{
    return [
        'Authorization' => 'Bearer '.$bearerToken,
        'Content-Type' => 'application/json',
    ];
}

describe('PATCH /api/v1/applications/{uuid} docker_registry_id', function () {
    test('associates a docker registry with the application through the API', function () {
        expect($this->application->docker_registry_id)->toBeNull();

        $this->withHeaders(dockerRegistryIdApiHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$this->application->uuid}", [
                'docker_registry_id' => $this->registry->id,
            ])
            ->assertOk();

        expect((int) $this->application->fresh()->docker_registry_id)->toBe($this->registry->id);
    });

    test('clears the docker registry association when null is sent', function () {
        $this->application->docker_registry_id = $this->registry->id;
        $this->application->save();

        $this->withHeaders(dockerRegistryIdApiHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$this->application->uuid}", [
                'docker_registry_id' => null,
            ])
            ->assertOk();

        expect($this->application->fresh()->docker_registry_id)->toBeNull();
    });

    test('rejects a non-existent docker_registry_id', function () {
        $this->withHeaders(dockerRegistryIdApiHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$this->application->uuid}", [
                'docker_registry_id' => 999999,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('docker_registry_id');

        expect($this->application->fresh()->docker_registry_id)->toBeNull();
    });
});

describe('POST /api/v1/applications/dockerimage docker_registry_id', function () {
    test('persists docker_registry_id when creating a docker image application', function () {
        $response = $this->withHeaders(dockerRegistryIdApiHeaders($this->bearerToken))
            ->postJson('/api/v1/applications/dockerimage', [
                'project_uuid' => $this->project->uuid,
                'environment_uuid' => $this->environment->uuid,
                'server_uuid' => $this->server->uuid,
                'docker_registry_image_name' => 'ghcr.io/coollabsio/example',
                'docker_registry_image_tag' => 'latest',
                'docker_registry_id' => $this->registry->id,
                'ports_exposes' => '80',
                'autogenerate_domain' => false,
            ]);

        $response->assertCreated();

        $application = Application::where('uuid', $response->json('uuid'))->firstOrFail();
        expect((int) $application->docker_registry_id)->toBe($this->registry->id);
    });
});
