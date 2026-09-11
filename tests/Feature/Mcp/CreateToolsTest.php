<?php

use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'cache.default' => 'array',
        'session.driver' => 'array',
        'queue.default' => 'sync',
        'app.maintenance.driver' => 'file',
    ]);

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
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
});

function mcpCreateCall(string $name, array $arguments, array $abilities)
{
    auth()->forgetGuards();
    $token = test()->user->createToken('mcp-create', $abilities)->plainTextToken;

    return test()->withHeaders([
        'Content-Type' => 'application/json',
        'Accept' => 'application/json, text/event-stream',
        'Authorization' => 'Bearer '.$token,
    ])->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => $name, 'arguments' => (object) $arguments],
    ]);
}

function mcpCreateJson($response): array
{
    return json_decode($response->json('result.content.0.text'), true);
}

test('create_database with write ability creates a postgres', function () {
    $response = mcpCreateCall('create_database', [
        'type' => 'postgresql',
        'project_uuid' => $this->project->uuid,
        'server_uuid' => $this->server->uuid,
        'environment_name' => 'production',
        'name' => 'mcp-pg',
    ], ['read', 'write']);

    $response->assertOk();
    expect($response->json('result.isError'))->toBeFalse();
    $body = mcpCreateJson($response);
    expect($body['data']['ok'])->toBeTrue()
        ->and($body['data']['uuid'])->not->toBeEmpty();
    expect(StandalonePostgresql::where('uuid', $body['data']['uuid'])->where('name', 'mcp-pg')->exists())->toBeTrue();
});

test('create_database is rejected without write ability', function () {
    $response = mcpCreateCall('create_database', [
        'type' => 'postgresql',
        'project_uuid' => $this->project->uuid,
        'server_uuid' => $this->server->uuid,
        'environment_name' => 'production',
    ], ['read']);

    $response->assertOk();
    expect($response->json('result.isError'))->toBeTrue();
    expect(StandalonePostgresql::count())->toBe(0);
});

test('create_database with instant_deploy requires deploy ability', function () {
    $response = mcpCreateCall('create_database', [
        'type' => 'postgresql',
        'project_uuid' => $this->project->uuid,
        'server_uuid' => $this->server->uuid,
        'environment_name' => 'production',
        'instant_deploy' => true,
    ], ['read', 'write']);

    $response->assertOk();
    expect($response->json('result.isError'))->toBeTrue();
    expect(StandalonePostgresql::count())->toBe(0);
});

test('create_project with write ability creates a project', function () {
    $response = mcpCreateCall('create_project', ['name' => 'mcp-proj'], ['read', 'write']);

    $response->assertOk();
    expect($response->json('result.isError'))->toBeFalse();
    $body = mcpCreateJson($response);
    expect(Project::where('uuid', $body['data']['uuid'])->where('name', 'mcp-proj')->exists())->toBeTrue();
});
