<?php

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
});

function mcpDeployPullRequestCall(array $arguments)
{
    auth()->forgetGuards();
    $token = test()->user->createToken('mcp-deploy-pr', ['read', 'deploy'])->plainTextToken;

    return test()->withHeaders([
        'Content-Type' => 'application/json',
        'Accept' => 'application/json, text/event-stream',
        'Authorization' => 'Bearer '.$token,
    ])->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => 'deploy', 'arguments' => (object) $arguments],
    ]);
}

test('deploy tool rejects a pull request id above the database integer range', function (int $pullRequestId) {
    $response = mcpDeployPullRequestCall([
        'uuid' => $this->application->uuid,
        'pull_request_id' => $pullRequestId,
    ]);

    $response->assertOk();
    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0.text'))->toContain('pull_request_id must be between 0 and 2147483647')
        ->and(ApplicationDeploymentQueue::query()->count())->toBe(0);
})->with([
    'above int4' => 3000000000,
    'negative' => -1,
]);
