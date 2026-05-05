<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
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

    $this->bearerToken = createApplicationTerminalApiToken($this->user, $this->team, ['deploy']);

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

    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first()
        ?? StandaloneDocker::factory()->create(['server_id' => $this->server->id, 'network' => 'coolify-test']);
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
});

function applicationTerminalAuthHeaders(string $bearerToken): array
{
    return [
        'Authorization' => 'Bearer '.$bearerToken,
        'Content-Type' => 'application/json',
    ];
}

function createApplicationTerminalApiToken(User $user, Team $team, array $abilities): string
{
    $plainTextToken = Str::random(40);
    $token = $user->tokens()->create([
        'name' => 'application-terminal-test-token',
        'token' => hash('sha256', $plainTextToken),
        'abilities' => $abilities,
        'team_id' => $team->id,
    ]);

    return $token->getKey().'|'.$plainTextToken;
}

function dockerPsApplicationContainerOutput(string $container = 'app-container', string $state = 'running'): string
{
    return json_encode([
        'Names' => $container,
        'State' => $state,
        'Labels' => 'coolify.applicationId='.test()->application->id,
    ], JSON_THROW_ON_ERROR)."\n";
}

describe('POST /api/v1/applications/{uuid}/exec', function () {
    test('runs a command in the application container and returns process output', function () {
        Process::fake([
            '*docker ps*' => Process::result(output: dockerPsApplicationContainerOutput()),
            '*docker exec*' => Process::result(output: "hello\n", errorOutput: '', exitCode: 0),
        ]);

        $response = $this->withHeaders(applicationTerminalAuthHeaders($this->bearerToken))
            ->postJson("/api/v1/applications/{$this->application->uuid}/exec", [
                'command' => 'php artisan about',
                'timeout' => 10,
            ]);

        $response->assertOk();
        $response->assertJson([
            'exit_code' => 0,
            'stdout' => "hello\n",
            'stderr' => '',
        ]);

        Process::assertRan(fn ($process) => str($process->command)->contains("docker exec 'app-container' sh -c 'php artisan about'"));
    });

    test('requires deploy token ability', function () {
        $readOnlyToken = createApplicationTerminalApiToken($this->user, $this->team, ['read']);

        $response = $this->withHeaders(applicationTerminalAuthHeaders($readOnlyToken))
            ->postJson("/api/v1/applications/{$this->application->uuid}/exec", [
                'command' => 'whoami',
            ]);

        $response->assertStatus(403);
    });

    test('returns 403 when terminal access is disabled', function () {
        $this->server->settings()->update(['is_terminal_enabled' => false]);

        $response = $this->withHeaders(applicationTerminalAuthHeaders($this->bearerToken))
            ->postJson("/api/v1/applications/{$this->application->uuid}/exec", [
                'command' => 'whoami',
            ]);

        $response->assertStatus(403);
        $response->assertJson(['message' => 'Terminal access is disabled on this server.']);
    });

    test('asks for a container when multiple running containers are found', function () {
        Process::fake([
            '*docker ps*' => Process::result(output: dockerPsApplicationContainerOutput('web').dockerPsApplicationContainerOutput('worker')),
        ]);

        $response = $this->withHeaders(applicationTerminalAuthHeaders($this->bearerToken))
            ->postJson("/api/v1/applications/{$this->application->uuid}/exec", [
                'command' => 'whoami',
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Multiple running containers found. Specify a container.');
        $response->assertJsonPath('containers.0.container', 'web');
        $response->assertJsonPath('containers.1.container', 'worker');
    });
});

describe('POST /api/v1/applications/{uuid}/terminal-sessions', function () {
    test('creates a terminal session descriptor for the application container', function () {
        Process::fake([
            '*docker ps*' => Process::result(output: dockerPsApplicationContainerOutput()),
        ]);

        $response = $this->withHeaders(applicationTerminalAuthHeaders($this->bearerToken))
            ->postJson("/api/v1/applications/{$this->application->uuid}/terminal-sessions", [
                'command' => 'cd /app',
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('websocket_path', '/terminal/ws');
        $response->assertJsonStructure([
            'websocket_path',
            'websocket_message' => ['command'],
        ]);
        expect($response->json('websocket_message.command'))->toContain("docker exec -it 'app-container'");
        expect($response->json('websocket_message.command'))->toContain('cd /app');
    });
});
