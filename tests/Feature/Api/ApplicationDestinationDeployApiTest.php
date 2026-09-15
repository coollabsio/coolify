<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Testing\Fluent\AssertableJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake([ApplicationDeploymentJob::class]);

    InstanceSettings::unguarded(fn () => InstanceSettings::query()->updateOrCreate(
        ['id' => 0],
        ['is_api_enabled' => true],
    ));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $this->token = $this->user->createToken('destination-deploy-test', ['deploy'])->plainTextToken;

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $this->additionalServer = Server::factory()->create(['team_id' => $this->team->id]);
    $this->additionalDestination = StandaloneDocker::query()->where('server_id', $this->additionalServer->id)->firstOrFail();

    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'git_repository' => 'https://github.com/coollabsio/coolify',
        'git_branch' => 'main',
        'git_commit_sha' => 'HEAD',
        'docker_registry_image_name' => 'ghcr.io/coollabsio/example',
    ]);
});

function destinationDeployHeaders(string $token): array
{
    return [
        'Authorization' => 'Bearer '.$token,
        'Content-Type' => 'application/json',
    ];
}

function attachAdditionalDestination(Application $application, StandaloneDocker $destination): void
{
    $application->additional_networks()->attach($destination->id, ['server_id' => $destination->server_id]);
}

test('queues a deployment only to the attached destination', function () {
    attachAdditionalDestination($this->application, $this->additionalDestination);

    $response = $this->withHeaders(destinationDeployHeaders($this->token))
        ->postJson("/api/v1/applications/{$this->application->uuid}/destinations/{$this->additionalDestination->uuid}/deploy");

    $response->assertOk()
        ->assertJson(fn (AssertableJson $json) => $json
            ->where('message', 'Deployment request queued.')
            ->whereType('deployment_uuid', 'string')
        );

    $deployment = ApplicationDeploymentQueue::query()->where('deployment_uuid', $response->json('deployment_uuid'))->firstOrFail();

    expect($deployment->server_id)->toBe($this->additionalServer->id)
        ->and((int) $deployment->destination_id)->toBe($this->additionalDestination->id)
        ->and($deployment->only_this_server)->toBeTruthy()
        ->and($deployment->is_api)->toBeTruthy()
        ->and($deployment->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value);

    Bus::assertDispatched(ApplicationDeploymentJob::class);
});

test('deploys to the attached destination while a primary deployment is in progress', function () {
    attachAdditionalDestination($this->application, $this->additionalDestination);

    ApplicationDeploymentQueue::create([
        'application_id' => $this->application->id,
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'deployment_uuid' => 'primary-in-progress',
        'commit' => 'HEAD',
        'pull_request_id' => 0,
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
    ]);

    $response = $this->withHeaders(destinationDeployHeaders($this->token))
        ->postJson("/api/v1/applications/{$this->application->uuid}/destinations/{$this->additionalDestination->uuid}/deploy");

    $response->assertOk()
        ->assertJson(fn (AssertableJson $json) => $json
            ->where('message', 'Deployment request queued.')
            ->whereType('deployment_uuid', 'string')
        );

    $deployment = ApplicationDeploymentQueue::query()->where('deployment_uuid', $response->json('deployment_uuid'))->firstOrFail();

    expect($deployment->server_id)->toBe($this->additionalServer->id)
        ->and($deployment->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value);

    Bus::assertDispatched(ApplicationDeploymentJob::class);
});

test('queues a deployment to the primary destination', function () {
    $response = $this->withHeaders(destinationDeployHeaders($this->token))
        ->postJson("/api/v1/applications/{$this->application->uuid}/destinations/{$this->destination->uuid}/deploy");

    $response->assertOk();

    $deployment = ApplicationDeploymentQueue::query()->where('deployment_uuid', $response->json('deployment_uuid'))->firstOrFail();

    expect($deployment->server_id)->toBe($this->server->id)
        ->and((int) $deployment->destination_id)->toBe($this->destination->id)
        ->and($deployment->only_this_server)->toBeTruthy();
});

test('returns 422 when the destination is not attached to the application', function () {
    $response = $this->withHeaders(destinationDeployHeaders($this->token))
        ->postJson("/api/v1/applications/{$this->application->uuid}/destinations/{$this->additionalDestination->uuid}/deploy");

    $response->assertStatus(422)
        ->assertJson(['message' => 'Destination is not attached to this application.']);

    expect(ApplicationDeploymentQueue::query()->count())->toBe(0);
});

test('returns 422 when a multi-destination application has no registry image', function () {
    attachAdditionalDestination($this->application, $this->additionalDestination);
    $this->application->update(['docker_registry_image_name' => null]);

    $response = $this->withHeaders(destinationDeployHeaders($this->token))
        ->postJson("/api/v1/applications/{$this->application->uuid}/destinations/{$this->additionalDestination->uuid}/deploy");

    $response->assertStatus(422)
        ->assertJson(['message' => 'A Docker registry image is required to deploy to multiple destinations.']);

    expect(ApplicationDeploymentQueue::query()->count())->toBe(0);
});

test('returns 404 for a destination owned by another team', function () {
    $otherTeam = Team::factory()->create();
    $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);
    $otherDestination = StandaloneDocker::query()->where('server_id', $otherServer->id)->firstOrFail();

    $response = $this->withHeaders(destinationDeployHeaders($this->token))
        ->postJson("/api/v1/applications/{$this->application->uuid}/destinations/{$otherDestination->uuid}/deploy");

    $response->assertNotFound();

    expect(ApplicationDeploymentQueue::query()->count())->toBe(0);
});

test('returns 404 for an application owned by another team', function () {
    $otherTeam = Team::factory()->create();
    $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);
    $otherDestination = StandaloneDocker::query()->where('server_id', $otherServer->id)->firstOrFail();
    $otherProject = Project::factory()->create(['team_id' => $otherTeam->id]);
    $otherEnvironment = Environment::factory()->create(['project_id' => $otherProject->id]);
    $otherApplication = Application::factory()->create([
        'environment_id' => $otherEnvironment->id,
        'destination_id' => $otherDestination->id,
        'destination_type' => $otherDestination->getMorphClass(),
    ]);

    $response = $this->withHeaders(destinationDeployHeaders($this->token))
        ->postJson("/api/v1/applications/{$otherApplication->uuid}/destinations/{$otherDestination->uuid}/deploy");

    $response->assertNotFound();

    expect(ApplicationDeploymentQueue::query()->count())->toBe(0);
});

test('returns 403 without the deploy ability', function () {
    $readToken = $this->user->createToken('destination-deploy-read', ['read'])->plainTextToken;

    $response = $this->withHeaders(destinationDeployHeaders($readToken))
        ->postJson("/api/v1/applications/{$this->application->uuid}/destinations/{$this->destination->uuid}/deploy");

    $response->assertForbidden();

    expect(ApplicationDeploymentQueue::query()->count())->toBe(0);
});

test('returns 403 for a team member', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);
    $memberToken = $member->createToken('destination-deploy-member', ['deploy'])->plainTextToken;

    $response = $this->withHeaders(destinationDeployHeaders($memberToken))
        ->postJson("/api/v1/applications/{$this->application->uuid}/destinations/{$this->destination->uuid}/deploy");

    $response->assertForbidden();

    expect(ApplicationDeploymentQueue::query()->count())->toBe(0);
});
