<?php

use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.store', 'array');
    InstanceSettings::query()->delete();
    $settings = new InstanceSettings(['is_mcp_server_enabled' => true, 'is_api_enabled' => true]);
    $settings->id = 0;
    $settings->save();
    Once::flush();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->otherTeam = Team::factory()->create();
    $this->otherUser = User::factory()->create();
    $this->otherTeam->members()->attach($this->otherUser->id, ['role' => 'owner']);

    session(['currentTeam' => $this->team]);
});

function mcpWriteToken(User $user, array $abilities = ['write']): string
{
    return $user->createToken('mcp-write', $abilities)->plainTextToken;
}

function mcpWriteCall(string $token, string $tool, array $arguments = [])
{
    // Each MCP call is a new HTTP request; do not reuse the user and token of an earlier call.
    app('auth')->forgetGuards();

    return test()->withHeaders([
        'Authorization' => 'Bearer '.$token,
        'Accept' => 'application/json, text/event-stream',
    ])->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => $tool, 'arguments' => (object) $arguments],
    ]);
}

function mcpWriteText($response): string
{
    return (string) ($response->json('result.content.0.text') ?? $response->json('error.message'));
}

function mcpWriteIsError($response): bool
{
    return $response->json('result.isError') === true || $response->json('error') !== null;
}

test('manage_project creates a project in the token team, not the session team', function () {
    $sessionTeam = Team::factory()->create();
    $sessionTeam->members()->attach($this->user->id, ['role' => 'owner']);
    $token = mcpWriteToken($this->user);
    session(['currentTeam' => $sessionTeam]);

    $response = mcpWriteCall($token, 'manage_project', ['action' => 'create', 'data' => ['name' => 'From MCP']]);

    $response->assertOk();
    expect(mcpWriteIsError($response))->toBeFalse();
    expect(Project::where('name', 'From MCP')->sole()->team_id)->toBe($this->team->id);
});

test('manage_project cannot change or delete a project of another team', function (string $action, array $data) {
    $foreign = Project::create(['name' => 'Theirs', 'team_id' => $this->otherTeam->id]);
    $token = mcpWriteToken($this->user);

    $response = mcpWriteCall($token, 'manage_project', ['action' => $action, 'uuid' => $foreign->uuid, 'confirm' => true, 'data' => $data]);

    expect(mcpWriteIsError($response))->toBeTrue();
    expect($foreign->fresh())->not->toBeNull()
        ->and($foreign->fresh()->name)->toBe('Theirs');
})->with([
    'update' => ['update', ['name' => 'Hijacked']],
    'delete' => ['delete', []],
    'create environment' => ['create_environment', ['name' => 'evil']],
]);

test('manage_project rejects read-only tokens and member tokens', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);

    $readResponse = mcpWriteCall(mcpWriteToken($this->user, ['read']), 'manage_project', ['action' => 'create', 'data' => ['name' => 'Nope']]);
    expect(mcpWriteIsError($readResponse))->toBeTrue()
        ->and(mcpWriteText($readResponse))->toContain('Tool [manage_project] not found');

    foreach ([['read'], ['write']] as $abilities) {
        $memberToken = $member->createToken('member', $abilities)->plainTextToken;
        $memberResponse = mcpWriteCall($memberToken, 'manage_project', ['action' => 'create', 'data' => ['name' => 'Nope']]);
        expect(mcpWriteIsError($memberResponse))->toBeTrue();
    }

    expect(Project::where('name', 'Nope')->exists())->toBeFalse();
});

test('manage_project delete needs confirm=true', function () {
    $project = Project::create(['name' => 'Mine', 'team_id' => $this->team->id]);
    $token = mcpWriteToken($this->user);

    $denied = mcpWriteCall($token, 'manage_project', ['action' => 'delete', 'uuid' => $project->uuid]);
    expect(mcpWriteIsError($denied))->toBeTrue()
        ->and(mcpWriteText($denied))->toContain('confirm=true')
        ->and($project->fresh())->not->toBeNull();

    $deleted = mcpWriteCall($token, 'manage_project', ['action' => 'delete', 'uuid' => $project->uuid, 'confirm' => true]);
    expect(mcpWriteIsError($deleted))->toBeFalse()
        ->and($project->fresh())->toBeNull();
});

test('manage_project returns REST validation errors', function () {
    $response = mcpWriteCall(mcpWriteToken($this->user), 'manage_project', ['action' => 'create', 'data' => ['name' => '']]);

    expect(mcpWriteIsError($response))->toBeTrue()
        ->and(mcpWriteText($response))->toContain('name');
});

test('write tools reject path parameters that try to reach other routes', function (string $uuid) {
    $response = mcpWriteCall(mcpWriteToken($this->user), 'manage_project', ['action' => 'update', 'uuid' => $uuid, 'data' => ['name' => 'x']]);

    expect(mcpWriteIsError($response))->toBeTrue()
        ->and(mcpWriteText($response))->toContain('invalid characters');
})->with(['../enable', 'a/b', '..', '%2e%2e', 'x?y=1']);

test('write tools reject an unknown action', function () {
    $response = mcpWriteCall(mcpWriteToken($this->user), 'manage_project', ['action' => 'drop_everything']);

    expect(mcpWriteIsError($response))->toBeTrue()
        ->and(mcpWriteText($response))->toContain('action must be one of');
});

test('write tools work when the REST API is disabled', function () {
    InstanceSettings::query()->whereKey(0)->update(['is_api_enabled' => false]);
    Once::flush();

    $response = mcpWriteCall(mcpWriteToken($this->user), 'manage_project', ['action' => 'create', 'data' => ['name' => 'Api Off']]);

    expect(mcpWriteIsError($response))->toBeFalse()
        ->and(Project::where('name', 'Api Off')->exists())->toBeTrue();
});
