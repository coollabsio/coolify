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
    InstanceSettings::query()->where('id', 0)->delete();
    InstanceSettings::query()->delete();
    $settings = new InstanceSettings(['is_mcp_server_enabled' => true]);
    $settings->id = 0;
    $settings->save();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = $this->project->environments()->first()
        ?? Environment::factory()->create(['project_id' => $this->project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'status' => 'exited:unhealthy',
    ]);
});

function mcpImproveToken(array $abilities = ['read']): string
{
    return test()->user->createToken('mcp-improve', $abilities)->plainTextToken;
}

function mcpImproveCall(string $name, array $arguments = [], ?string $token = null)
{
    $token ??= mcpImproveToken();

    // Ensure each call resolves the Bearer token freshly (no guard bleed between tokens).
    auth()->forgetGuards();

    return test()->withHeaders([
        'Content-Type' => 'application/json',
        'Accept' => 'application/json, text/event-stream',
        'Authorization' => 'Bearer '.$token,
    ])->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => [
            'name' => $name,
            'arguments' => (object) $arguments,
        ],
    ]);
}

function mcpImproveJson($response): array
{
    return json_decode($response->json('result.content.0.text'), true);
}

test('get_logs returns structured next_tools when not running', function () {
    $response = mcpImproveCall('get_logs', [
        'resource' => 'application',
        'uuid' => $this->application->uuid,
    ], mcpImproveToken(['read', 'read:sensitive']));
    $response->assertOk();
    $body = mcpImproveJson($response);
    expect($body['data']['ok'])->toBeFalse()
        ->and($body['data']['reason'])->toBe('not_running')
        ->and(collect($body['data']['next_tools'])->pluck('tool')->all())
        ->toContain('list_deployments', 'list_unhealthy_resources');
});

test('coolify_help returns essentials catalog', function () {
    $response = mcpImproveCall('coolify_help', ['intent' => 'essentials']);
    $response->assertOk();
    $body = mcpImproveJson($response);
    expect($body['data']['catalog']['essentials']['tools'])->toContain('coolify_help', 'control', 'search_resources');
});

test('list_unhealthy_resources sample_only returns summary', function () {
    $response = mcpImproveCall('list_unhealthy_resources', [
        'sample_only' => true,
        'sample_per_type' => 3,
    ]);
    $response->assertOk();
    $body = mcpImproveJson($response);
    expect($body['data']['sample_only'])->toBeTrue()
        ->and($body['data']['summary'])->toHaveKeys(['total', 'applications', 'servers'])
        ->and($body['data']['samples'])->toHaveKeys(['applications', 'servers', 'services', 'databases']);
});

test('control requires deploy ability and stop requires confirm', function () {
    $denied = mcpImproveCall('control', [
        'resource' => 'application',
        'action' => 'start',
        'uuid' => $this->application->uuid,
    ]);
    expect($denied->json('result.isError'))->toBeTrue();
    expect($denied->json('result.content.0.text'))->toContain('Missing required permissions');

    $deployToken = $this->user->createToken('mcp-deploy-stop', ['read', 'deploy'])->plainTextToken;
    $stop = mcpImproveCall('control', [
        'resource' => 'application',
        'action' => 'stop',
        'uuid' => $this->application->uuid,
    ], $deployToken);

    expect($stop->json('result.isError'))->toBeTrue();
    expect((string) $stop->json('result.content.0.text'))->toContain('confirm=true');
});

test('tools list includes coolify_help and control', function () {
    $token = mcpImproveToken();
    $response = test()->withHeaders([
        'Content-Type' => 'application/json',
        'Accept' => 'application/json, text/event-stream',
        'Authorization' => 'Bearer '.$token,
    ])->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
        'params' => (object) [],
    ]);
    $response->assertOk();
    $names = collect($response->json('result.tools'))->pluck('name')->all();
    expect($names)->toContain('coolify_help', 'control', 'deploy', 'cancel_deployment');
});
