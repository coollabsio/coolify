<?php

use App\Mcp\Servers\CoolifyServer;
use App\Mcp\Tools\CreateApplication;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['cache.default' => 'array', 'session.driver' => 'array', 'queue.default' => 'sync', 'app.maintenance.driver' => 'file']);
    InstanceSettings::query()->delete();
    $settings = new InstanceSettings(['is_mcp_server_enabled' => true]);
    $settings->id = 0;
    $settings->save();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
});

function mcpAppCall(array $arguments, array $abilities)
{
    auth()->forgetGuards();
    $token = test()->user->createToken('mcp-app', $abilities)->plainTextToken;

    return test()->withHeaders([
        'Content-Type' => 'application/json',
        'Accept' => 'application/json, text/event-stream',
        'Authorization' => 'Bearer '.$token,
    ])->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'create_application', 'arguments' => (object) $arguments],
    ]);
}

function mcpAppBase(): array
{
    return ['project_uuid' => test()->project->uuid, 'server_uuid' => test()->server->uuid, 'environment_name' => 'production'];
}

test('create_application creates a dockerimage app with write ability', function () {
    $response = mcpAppCall(mcpAppBase() + ['type' => 'dockerimage', 'docker_registry_image_name' => 'nginx', 'ports_exposes' => '80', 'name' => 'mcp-nginx'], ['read', 'write']);

    $response->assertOk();
    expect($response->json('result.isError'))->toBeFalse();
    $body = json_decode($response->json('result.content.0.text'), true);
    expect($body['data']['ok'])->toBeTrue()
        ->and(Application::where('uuid', $body['data']['uuid'])->where('name', 'mcp-nginx')->exists())->toBeTrue()
        ->and($body['data']['next_tools'][0]['tool'])->toBe('get_application');
});

test('create_application creates a dockerfile app from plain-text dockerfile', function () {
    $response = mcpAppCall(mcpAppBase() + ['type' => 'dockerfile', 'dockerfile' => "FROM nginx\nEXPOSE 8080\n"], ['read', 'write']);

    $body = json_decode($response->json('result.content.0.text'), true);
    expect($response->json('result.isError'))->toBeFalse()
        ->and((string) Application::where('uuid', $body['data']['uuid'])->first()->ports_exposes)->toBe('8080');
});

test('create_application is rejected without write ability', function () {
    $response = mcpAppCall(mcpAppBase() + ['type' => 'dockerimage', 'docker_registry_image_name' => 'nginx', 'ports_exposes' => '80'], ['read']);

    expect($response->json('result.isError'))->toBeTrue()->and(Application::count())->toBe(0);
});

test('create_application with instant_deploy requires deploy ability', function () {
    Queue::fake();
    $denied = mcpAppCall(mcpAppBase() + ['type' => 'dockerimage', 'docker_registry_image_name' => 'nginx', 'ports_exposes' => '80', 'instant_deploy' => true], ['read', 'write']);
    expect($denied->json('result.isError'))->toBeTrue();

    $ok = mcpAppCall(mcpAppBase() + ['type' => 'dockerimage', 'docker_registry_image_name' => 'nginx', 'ports_exposes' => '80', 'instant_deploy' => true], ['read', 'write', 'deploy']);
    expect($ok->json('result.isError'))->toBeFalse()->and(ApplicationDeploymentQueue::count())->toBe(1);
});

test('create_application validates per-type required fields', function () {
    $response = mcpAppCall(mcpAppBase() + ['type' => 'public', 'git_branch' => 'main'], ['read', 'write']);

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0.text'))->toContain('git_repository');
});

test('create_application reports placement errors', function () {
    $response = mcpAppCall(['project_uuid' => 'nope', 'server_uuid' => $this->server->uuid, 'environment_name' => 'production', 'type' => 'dockerimage', 'docker_registry_image_name' => 'nginx', 'ports_exposes' => '80'], ['read', 'write']);

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0.text'))->toContain('was not found in this team');
});

test('create_application is registered and marked mutating', function () {
    expect(CoolifyServer::toolClasses())->toContain(CreateApplication::class)
        ->and(CoolifyServer::MUTATING_TOOL_CLASSES)->toContain(CreateApplication::class)
        ->and(CoolifyServer::readToolClasses())->not->toContain(CreateApplication::class);
});
