<?php

use App\Enums\ApplicationDeploymentStatus;
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
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'cache.default' => 'array',
        'session.driver' => 'array',
        'app.maintenance.driver' => 'file',
    ]);
    Queue::fake();

    InstanceSettings::query()->delete();
    $settings = new InstanceSettings(['is_mcp_server_enabled' => true]);
    $settings->id = 0;
    $settings->save();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = $project->environments()->first()
        ?? Environment::factory()->create(['project_id' => $project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $this->running = ApplicationDeploymentQueue::create([
        'application_id' => $this->application->id,
        'deployment_uuid' => 'running-deployment',
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'pull_request_id' => 0,
        'commit' => 'abc1234',
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
    ]);
});

function mcpDeployQueueCall(string $tool, array $arguments)
{
    auth()->forgetGuards();
    $token = test()->user->createToken('mcp-deploy-queue', ['read', 'deploy'])->plainTextToken;

    return test()->withHeaders([
        'Content-Type' => 'application/json',
        'Accept' => 'application/json, text/event-stream',
        'Authorization' => 'Bearer '.$token,
    ])->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => $tool, 'arguments' => (object) $arguments],
    ]);
}

function mcpDeployQueueNewDeployment(): ?ApplicationDeploymentQueue
{
    return ApplicationDeploymentQueue::query()->where('deployment_uuid', '!=', 'running-deployment')->first();
}

test('deploy tool queues behind a deployment of the same application that is in progress', function () {
    $response = mcpDeployQueueCall('deploy', ['uuid' => $this->application->uuid]);

    $response->assertOk();
    expect($response->json('result.isError'))->toBeFalse()
        ->and(mcpDeployQueueNewDeployment()?->status)->toBe(ApplicationDeploymentStatus::QUEUED->value)
        ->and($this->running->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value);
});

test('deploy tool skips a deployment of a commit that is already in progress', function () {
    $this->application->update(['git_commit_sha' => 'abc1234']);

    $response = mcpDeployQueueCall('deploy', ['uuid' => $this->application->uuid]);

    $response->assertOk();
    expect($response->json('result.content.0.text'))->toContain('Deployment already queued for this commit.')
        ->toContain('running-deployment')
        ->and(mcpDeployQueueNewDeployment())->toBeNull();
});

test('control start queues behind a deployment of the same application that is in progress', function () {
    $response = mcpDeployQueueCall('control', ['resource' => 'application', 'action' => 'start', 'uuid' => $this->application->uuid]);

    $response->assertOk();
    expect($response->json('result.isError'))->toBeFalse($response->json('result.content.0.text'))
        ->and(mcpDeployQueueNewDeployment()?->status)->toBe(ApplicationDeploymentStatus::QUEUED->value);
});
