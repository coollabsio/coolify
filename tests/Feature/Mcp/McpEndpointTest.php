<?php

use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;

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
});

function mcpPost(array $payload, ?string $token = null)
{
    $headers = [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json, text/event-stream',
    ];
    if ($token) {
        $headers['Authorization'] = 'Bearer '.$token;
    }

    return test()->withHeaders($headers)->postJson('/mcp', $payload);
}

function mcpListTools(string $token)
{
    return mcpPost([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
        'params' => (object) [],
    ], $token);
}

function mcpCallTool(string $token, string $name, array $arguments = [])
{
    return mcpPost([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => [
            'name' => $name,
            'arguments' => (object) $arguments,
        ],
    ], $token);
}

function mcpToolJson($response): array
{
    return json_decode($response->json('result.content.0.text'), true);
}

test('MCP endpoint returns 404 when the instance setting is disabled', function () {
    InstanceSettings::query()->where('id', 0)->update(['is_mcp_server_enabled' => false]);
    Once::flush();

    $response = mcpPost(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
    $response->assertStatus(404);
});

test('MCP endpoint rejects unauthenticated requests', function () {
    $response = mcpPost(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
    $response->assertStatus(401);
});

test('MCP endpoint lists tools for an authenticated token', function () {
    $token = $this->user->createToken('mcp-read', ['read'])->plainTextToken;

    $response = mcpListTools($token);
    $response->assertOk();

    $toolNames = collect($response->json('result.tools'))->pluck('name')->all();
    expect($toolNames)->toContain(
        'get_infrastructure_overview',
        'list_servers',
        'get_server',
        'list_projects',
        'list_applications',
        'get_application',
        'list_databases',
        'get_database',
        'list_services',
        'get_service',
    );
    expect($toolNames)->not->toContain('get_resource_status');
});

test('list_projects returns summary + pagination scoped to the token team', function () {
    $project = Project::create(['name' => 'Mine', 'team_id' => $this->team->id]);

    $otherTeam = Team::factory()->create();
    Project::create(['name' => 'Theirs', 'team_id' => $otherTeam->id]);

    $token = $this->user->createToken('mcp-read', ['read'])->plainTextToken;

    $response = mcpCallTool($token, 'list_projects');
    $response->assertOk();

    $body = mcpToolJson($response);

    expect($body)->toHaveKey('data');
    expect($body)->toHaveKey('_pagination');
    expect($body['_pagination']['total'])->toBe(1);
    expect($body['_pagination']['per_page'])->toBe(50);
    expect($body['_pagination'])->not->toHaveKey('next');

    $uuids = collect($body['data'])->pluck('uuid')->all();
    $names = collect($body['data'])->pluck('name')->all();
    expect($uuids)->toContain($project->uuid);
    expect($names)->not->toContain('Theirs');
    expect($body['data'][0])->toHaveKeys(['uuid', 'name', 'description']);
});

test('list_projects paginates with per_page cap at 100', function () {
    for ($i = 0; $i < 3; $i++) {
        Project::create(['name' => "P{$i}", 'team_id' => $this->team->id]);
    }
    $token = $this->user->createToken('mcp-read', ['read'])->plainTextToken;

    $response = mcpCallTool($token, 'list_projects', ['per_page' => 2, 'page' => 1]);
    $body = mcpToolJson($response);

    expect($body['_pagination']['total'])->toBe(3);
    expect($body['_pagination']['total_pages'])->toBe(2);
    expect($body['_pagination']['next']['args'])->toMatchArray(['page' => 2, 'per_page' => 2]);
    expect($body['data'])->toHaveCount(2);

    // Verify max cap
    $capped = mcpCallTool($token, 'list_projects', ['per_page' => 500]);
    $cappedBody = mcpToolJson($capped);
    expect($cappedBody['_pagination']['per_page'])->toBe(100);
});

test('get_infrastructure_overview returns counts', function () {
    Project::create(['name' => 'One', 'team_id' => $this->team->id]);
    Project::create(['name' => 'Two', 'team_id' => $this->team->id]);

    $token = $this->user->createToken('mcp-read', ['read'])->plainTextToken;

    $response = mcpCallTool($token, 'get_infrastructure_overview');
    $response->assertOk();

    $body = mcpToolJson($response);
    expect($body)->toHaveKey('data');
    expect($body['data'])->toHaveKeys(['coolify_version', 'servers', 'projects', 'counts']);
    expect($body['data']['counts']['projects'])->toBe(2);
    expect($body['data']['projects'])->toHaveCount(2);
    expect($body['data']['projects'][0])->toHaveKey('counts');
});

test('get_server scrubs sensitive nested data and exposes connection_timeout', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    // creating hook auto-generates a sentinel_token; bump connection_timeout
    // via saveQuietly to avoid triggering restartSentinel.
    $server->settings->forceFill(['connection_timeout' => 42])->saveQuietly();

    $token = $this->user->createToken('mcp-read', ['read'])->plainTextToken;

    $response = mcpCallTool($token, 'get_server', ['uuid' => $server->uuid]);
    $response->assertOk();

    $body = mcpToolJson($response);
    $raw = json_encode($body);

    expect($raw)->not->toContain('sentinel_token');
    expect($raw)->not->toContain('"team_id"');
    expect($raw)->not->toContain('"private_key_id"');
    expect($body['data']['connection_timeout'])->toBe(42);
    expect($body['data']['uuid'])->toBe($server->uuid);
});

test('tool calls fail when the token lacks the read ability', function () {
    $token = $this->user->createToken('mcp-no-abilities', [])->plainTextToken;

    $response = mcpCallTool($token, 'list_projects');
    $response->assertOk();

    expect($response->json('result.isError'))->toBeTrue();
    expect($response->json('result.content.0.text'))->toContain('Missing required permissions');
});

test('MCP rejects token when user no longer belongs to token team', function () {
    Project::create(['name' => 'Hidden', 'team_id' => $this->team->id]);
    $token = $this->user->createToken('mcp-read', ['read'])->plainTextToken;

    $this->team->members()->detach($this->user->id);

    $response = mcpCallTool($token, 'list_projects');

    $response->assertUnauthorized();
});
