<?php

use App\Actions\Service\StartService;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Once;
use Lorisleiva\Actions\Decorators\JobDecorator;

uses(RefreshDatabase::class);

function isStartServiceJob($job): bool
{
    return $job instanceof JobDecorator && $job->getAction() instanceof StartService;
}

function instantDeployHeaders(string $token): array
{
    return ['Authorization' => 'Bearer '.$token, 'Content-Type' => 'application/json'];
}

beforeEach(function () {
    Queue::fake();
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);
    Once::flush();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $this->bearerToken = $this->user->createToken('test-token', ['*'])->plainTextToken;

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = $this->project->environments()->first();
    $this->compose = base64_encode("services:\n  app:\n    image: nginx:alpine\n");
});

test('instant_deploy queues StartService once creation and url validation succeed', function () {
    $response = $this->withHeaders(instantDeployHeaders($this->bearerToken))
        ->postJson('/api/v1/services', [
            'project_uuid' => $this->project->uuid,
            'server_uuid' => $this->server->uuid,
            'environment_name' => $this->environment->name,
            'docker_compose_raw' => $this->compose,
            'instant_deploy' => true,
        ]);

    $response->assertStatus(201);
    Queue::assertPushed(JobDecorator::class, fn ($job) => isStartServiceJob($job));
});

test('a url failure deletes the service and never queues a deploy', function () {
    $response = $this->withHeaders(instantDeployHeaders($this->bearerToken))
        ->postJson('/api/v1/services', [
            'project_uuid' => $this->project->uuid,
            'server_uuid' => $this->server->uuid,
            'environment_name' => $this->environment->name,
            'docker_compose_raw' => $this->compose,
            'instant_deploy' => true,
            // A non-http(s) scheme fails URL validation, so the service is deleted
            // with a 422 before any deploy could run.
            'urls' => [['name' => 'app', 'url' => 'ftp://bad.example.com']],
        ]);

    $response->assertStatus(422);
    Queue::assertNotPushed(JobDecorator::class, fn ($job) => isStartServiceJob($job));
    expect(Service::count())->toBe(0);
});
