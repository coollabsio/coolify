<?php

use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->bearerToken = createServerTerminalApiToken($this->user, $this->team, ['deploy']);

    $this->privateKey = PrivateKey::create([
        'name' => 'Test Key',
        'private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW
QyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevAAAAJi/QySHv0Mk
hwAAAAtzc2gtZWQyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevA
AAAECBQw4jg1WRT2IGHMncCiZhURCts2s24HoDS0thHnnRKVuGmoeGq/pojrsyP1pszcNV
uZx9iFkCELtxrh31QJ68AAAAEXNhaWxANzZmZjY2ZDJlMmRkAQIDBA==
-----END OPENSSH PRIVATE KEY-----',
        'team_id' => $this->team->id,
    ]);

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
        'ip' => 'coolify-testing-host',
        'user' => 'root',
    ]);
    $this->server->settings()->update([
        'is_terminal_enabled' => true,
        'force_disabled' => false,
    ]);
});

function serverTerminalAuthHeaders(string $bearerToken): array
{
    return [
        'Authorization' => 'Bearer '.$bearerToken,
        'Content-Type' => 'application/json',
    ];
}

function createServerTerminalApiToken(User $user, Team $team, array $abilities): string
{
    $plainTextToken = Str::random(40);
    $token = $user->tokens()->create([
        'name' => 'server-terminal-test-token',
        'token' => hash('sha256', $plainTextToken),
        'abilities' => $abilities,
        'team_id' => $team->id,
    ]);

    return $token->getKey().'|'.$plainTextToken;
}

describe('POST /api/v1/servers/{uuid}/exec', function () {
    test('runs a command and returns process output', function () {
        Process::fake([
            '*' => Process::result(output: "hello\n", errorOutput: '', exitCode: 0),
        ]);

        $response = $this->withHeaders(serverTerminalAuthHeaders($this->bearerToken))
            ->postJson("/api/v1/servers/{$this->server->uuid}/exec", [
                'command' => 'echo hello',
                'timeout' => 10,
            ]);

        $response->assertOk();
        $response->assertJson([
            'exit_code' => 0,
            'stdout' => "hello\n",
            'stderr' => '',
        ]);
    });

    test('requires deploy token ability', function () {
        $readOnlyToken = createServerTerminalApiToken($this->user, $this->team, ['read']);

        $response = $this->withHeaders(serverTerminalAuthHeaders($readOnlyToken))
            ->postJson("/api/v1/servers/{$this->server->uuid}/exec", [
                'command' => 'whoami',
            ]);

        $response->assertStatus(403);
    });

    test('returns 403 when terminal access is disabled', function () {
        $this->server->settings()->update(['is_terminal_enabled' => false]);

        $response = $this->withHeaders(serverTerminalAuthHeaders($this->bearerToken))
            ->postJson("/api/v1/servers/{$this->server->uuid}/exec", [
                'command' => 'whoami',
            ]);

        $response->assertStatus(403);
        $response->assertJson(['message' => 'Terminal access is disabled on this server.']);
    });

    test('rejects unknown request fields', function () {
        $response = $this->withHeaders(serverTerminalAuthHeaders($this->bearerToken))
            ->postJson("/api/v1/servers/{$this->server->uuid}/exec", [
                'command' => 'whoami',
                'extra' => 'not allowed',
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.extra.0', 'This field is not allowed.');
    });
});

describe('POST /api/v1/servers/{uuid}/terminal-sessions', function () {
    test('creates a terminal session descriptor', function () {
        $response = $this->withHeaders(serverTerminalAuthHeaders($this->bearerToken))
            ->postJson("/api/v1/servers/{$this->server->uuid}/terminal-sessions", [
                'command' => 'cd /tmp',
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('websocket_path', '/terminal/ws');
        $response->assertJsonStructure([
            'websocket_path',
            'websocket_message' => ['command'],
        ]);
        expect($response->json('websocket_message.command'))->toContain('cd /tmp');
        expect($response->json('websocket_message.command'))->toContain('coolify-testing-host');
    });

    test('requires deploy token ability', function () {
        $readOnlyToken = createServerTerminalApiToken($this->user, $this->team, ['read']);

        $response = $this->withHeaders(serverTerminalAuthHeaders($readOnlyToken))
            ->postJson("/api/v1/servers/{$this->server->uuid}/terminal-sessions", [
                'command' => 'whoami',
            ]);

        $response->assertStatus(403);
    });

    test('returns 403 when terminal access is disabled', function () {
        $this->server->settings()->update(['is_terminal_enabled' => false]);

        $response = $this->withHeaders(serverTerminalAuthHeaders($this->bearerToken))
            ->postJson("/api/v1/servers/{$this->server->uuid}/terminal-sessions", [
                'command' => 'whoami',
            ]);

        $response->assertStatus(403);
        $response->assertJson(['message' => 'Terminal access is disabled on this server.']);
    });

    test('returns 404 for servers outside the token team', function () {
        $otherTeam = Team::factory()->create();
        $otherServer = Server::factory()->create([
            'team_id' => $otherTeam->id,
            'private_key_id' => $this->privateKey->id,
        ]);

        $response = $this->withHeaders(serverTerminalAuthHeaders($this->bearerToken))
            ->postJson("/api/v1/servers/{$otherServer->uuid}/terminal-sessions", [
                'command' => 'whoami',
            ]);

        $response->assertStatus(404);
    });
});
