<?php

use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $plainTextToken = Str::random(40);
    $token = $this->user->tokens()->create([
        'name' => 'test-token',
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

function createDockerImageApplication(Environment $environment, StandaloneDocker $destination): Application
{
    return Application::factory()->create([
        'uuid' => (string) Str::uuid(),
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        'build_pack' => 'dockerimage',
        'docker_registry_image_name' => 'ghcr.io/coollabsio/example',
        'docker_registry_image_tag' => 'latest',
    ]);
}

test('it queues a docker image preview deployment and stores the preview tag', function () {
    $application = createDockerImageApplication($this->environment, $this->destination);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$this->bearerToken,
    ])->postJson('/api/v1/deploy', [
        'uuid' => $application->uuid,
        'pull_request_id' => 1234,
        'docker_tag' => 'pr_1234',
    ]);

    $response->assertSuccessful();
    $response->assertJsonPath('deployments.0.resource_uuid', $application->uuid);

    $preview = ApplicationPreview::query()
        ->where('application_id', $application->id)
        ->where('pull_request_id', 1234)
        ->first();

    expect($preview)->not()->toBeNull();
    expect($preview->docker_registry_image_tag)->toBe('pr_1234');

    $deployment = $application->deployment_queue()->latest('id')->first();

    expect($deployment)->not()->toBeNull();
    expect($deployment->pull_request_id)->toBe(1234);
    expect($deployment->docker_registry_image_tag)->toBe('pr_1234');
});

test('it updates an existing docker image preview tag when redeploying through the api', function () {
    $application = createDockerImageApplication($this->environment, $this->destination);

    ApplicationPreview::create([
        'application_id' => $application->id,
        'pull_request_id' => 99,
        'pull_request_html_url' => '',
        'docker_registry_image_tag' => 'pr_99_old',
    ]);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$this->bearerToken,
    ])->postJson('/api/v1/deploy', [
        'uuid' => $application->uuid,
        'pull_request_id' => 99,
        'docker_tag' => 'pr_99_new',
        'force' => true,
    ]);

    $response->assertSuccessful();

    $preview = ApplicationPreview::query()
        ->where('application_id', $application->id)
        ->where('pull_request_id', 99)
        ->first();

    expect($preview->docker_registry_image_tag)->toBe('pr_99_new');
});

test('it rejects docker_tag without pull_request_id', function () {
    $application = createDockerImageApplication($this->environment, $this->destination);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$this->bearerToken,
    ])->postJson('/api/v1/deploy', [
        'uuid' => $application->uuid,
        'docker_tag' => 'pr_1234',
    ]);

    $response->assertStatus(400);
    $response->assertJson(['message' => 'docker_tag requires pull_request_id.']);
});

test('it rejects docker_tag for non docker image applications', function () {
    $application = Application::factory()->create([
        'uuid' => (string) Str::uuid(),
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => StandaloneDocker::class,
        'build_pack' => 'nixpacks',
    ]);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$this->bearerToken,
    ])->postJson('/api/v1/deploy', [
        'uuid' => $application->uuid,
        'pull_request_id' => 7,
        'docker_tag' => 'pr_7',
    ]);

    $response->assertSuccessful();
    $response->assertJsonPath('deployments.0.message', 'docker_tag can only be used with Docker Image applications.');
});
