<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $plainTextToken = Str::random(40);
    $token = $this->user->tokens()->create([
        'name' => 'docker-registry-validation-api-test-'.Str::random(6),
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

function dockerRegistryApiHeaders(string $bearerToken): array
{
    return [
        'Authorization' => 'Bearer '.$bearerToken,
        'Content-Type' => 'application/json',
    ];
}

function makeDockerRegistryValidationApplication(array $overrides = []): Application
{
    return Application::factory()->create(array_merge([
        'environment_id' => test()->environment->id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
        'build_pack' => 'nixpacks',
        'docker_registry_image_name' => 'ghcr.io/coollabsio/example',
        'docker_registry_image_tag' => 'latest',
    ], $overrides));
}

describe('PATCH /api/v1/applications/{uuid} docker registry image validation', function () {
    test('rejects shell metacharacters in docker registry image name without persisting them', function () {
        $application = makeDockerRegistryValidationApplication();

        $response = $this->withHeaders(dockerRegistryApiHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$application->uuid}", [
                'docker_registry_image_name' => 'coolify/poc$(touch /tmp/pwned)',
                'docker_registry_image_tag' => 'latest',
            ]);

        $response->assertUnprocessable()
            ->assertInvalid(['docker_registry_image_name']);

        $application->refresh();
        expect($application->docker_registry_image_name)->toBe('ghcr.io/coollabsio/example')
            ->and($application->docker_registry_image_tag)->toBe('latest');
    });

    test('rejects shell metacharacters in docker registry image tag without persisting them', function () {
        $application = makeDockerRegistryValidationApplication();

        $response = $this->withHeaders(dockerRegistryApiHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$application->uuid}", [
                'docker_registry_image_name' => 'ghcr.io/coollabsio/example',
                'docker_registry_image_tag' => 'latest$(touch /tmp/pwned)',
            ]);

        $response->assertUnprocessable()
            ->assertInvalid(['docker_registry_image_tag']);

        $application->refresh();
        expect($application->docker_registry_image_name)->toBe('ghcr.io/coollabsio/example')
            ->and($application->docker_registry_image_tag)->toBe('latest');
    });

    test('accepts valid docker registry image values', function () {
        $application = makeDockerRegistryValidationApplication();

        $response = $this->withHeaders(dockerRegistryApiHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$application->uuid}", [
                'docker_registry_image_name' => 'registry.example.com:5000/team/app',
                'docker_registry_image_tag' => 'v1.2.3',
            ]);

        $response->assertOk();

        $application->refresh();
        expect($application->docker_registry_image_name)->toBe('registry.example.com:5000/team/app')
            ->and($application->docker_registry_image_tag)->toBe('v1.2.3');
    });
});
