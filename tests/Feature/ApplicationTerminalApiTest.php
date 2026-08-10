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
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);
    config(['api.rate_limit' => 1000]);
    RateLimiter::for('api', fn (Request $request) => Limit::perMinute(1000)->by($request->user()?->id ?: $request->ip()));
    Cache::flush();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->bearerToken = createApplicationTerminalApiToken($this->user, $this->team, ['terminal']);

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

function dockerPsApplicationContainerOutput(string $container = 'app-container', string $state = 'running', ?int $pullRequestId = null): string
{
    $labels = ['coolify.applicationId='.test()->application->id];
    if ($pullRequestId !== null) {
        $labels[] = "coolify.pullRequestId={$pullRequestId}";
    }

    return json_encode([
        'Names' => $container,
        'State' => $state,
        'Labels' => implode(',', $labels),
    ], JSON_THROW_ON_ERROR)."\n";
}

describe('POST /api/v1/applications/{uuid}/exec', function () {
    test('runs a command in the application container and returns process output', function () {
        Process::fake([
            '*docker ps*' => Process::result(output: dockerPsApplicationContainerOutput()),
            '*docker exec*' => Process::result(output: "hello\n", errorOutput: "warning\n", exitCode: 0),
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
            'stderr' => "warning\n",
        ]);

        Process::assertRan(fn ($process) => str($process->command)->contains("docker exec 'app-container' sh -c 'php artisan about'"));
    });

    test('truncates oversized command output', function () {
        Process::fake([
            '*docker ps*' => Process::result(output: dockerPsApplicationContainerOutput()),
            '*docker exec*' => Process::result(output: str_repeat('a', 70000), errorOutput: str_repeat('b', 70000)),
        ]);

        $response = $this->withHeaders(applicationTerminalAuthHeaders($this->bearerToken))
            ->postJson("/api/v1/applications/{$this->application->uuid}/exec", [
                'command' => 'cat large-file',
            ]);

        $response->assertOk();
        expect(strlen($response->json('stdout')))->toBe(65536)
            ->and(strlen($response->json('stderr')))->toBe(65536)
            ->and($response->json('stdout'))->toEndWith('[... Output truncated at 65536 bytes ...]')
            ->and($response->json('stderr'))->toEndWith('[... Output truncated at 65536 bytes ...]');
    });

    test('uses the requested timeout for ssh and preserves timeout output', function () {
        Process::fake([
            '*docker ps*' => Process::result(output: dockerPsApplicationContainerOutput()),
            '*docker exec*' => Process::result(output: '', errorOutput: "timed out\n", exitCode: 124),
        ]);

        $response = $this->withHeaders(applicationTerminalAuthHeaders($this->bearerToken))
            ->postJson("/api/v1/applications/{$this->application->uuid}/exec", [
                'command' => 'sleep 30',
                'timeout' => 3,
            ]);

        $response->assertOk();
        $response->assertJson([
            'exit_code' => 124,
            'stdout' => '',
            'stderr' => "timed out\n",
        ]);

        Process::assertRan(fn ($process) => str($process->command)->contains('timeout 3 ssh')
            && str($process->command)->contains("docker exec 'app-container'")
            && $process->timeout === 8);
    });

    test('requires terminal token ability', function () {
        $deployToken = createApplicationTerminalApiToken($this->user, $this->team, ['deploy']);

        $response = $this->withHeaders(applicationTerminalAuthHeaders($deployToken))
            ->postJson("/api/v1/applications/{$this->application->uuid}/exec", [
                'command' => 'whoami',
            ]);

        $response->assertStatus(403);
    });

    test('rejects timeouts over ten seconds', function () {
        $response = $this->withHeaders(applicationTerminalAuthHeaders($this->bearerToken))
            ->postJson("/api/v1/applications/{$this->application->uuid}/exec", [
                'command' => 'whoami',
                'timeout' => 11,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('timeout');
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

    test('returns 403 when the terminal API is disabled for the team', function () {
        $this->team->update(['is_terminal_api_enabled' => false]);

        $response = $this->withHeaders(applicationTerminalAuthHeaders($this->bearerToken))
            ->postJson("/api/v1/applications/{$this->application->uuid}/exec", [
                'command' => 'whoami',
            ]);

        $response->assertStatus(403);
        $response->assertJson(['message' => 'Terminal API is disabled for this team.']);
    });

    test('shares the server concurrent command limit', function () {
        Process::fake([
            '*docker ps*' => Process::result(output: dockerPsApplicationContainerOutput()),
        ]);

        $lockName = "terminal-api-exec:concurrent:team:{$this->team->id}:server:{$this->server->uuid}:";
        $locks = collect(range(1, 3))->map(function (int $slot) use ($lockName) {
            $lock = Cache::lock($lockName.$slot, 15);
            expect($lock->acquire())->toBeTrue();

            return $lock;
        });

        try {
            $this->withHeaders(applicationTerminalAuthHeaders($this->bearerToken))
                ->postJson("/api/v1/applications/{$this->application->uuid}/exec", [
                    'command' => 'whoami',
                ])
                ->assertStatus(429)
                ->assertHeader('Retry-After', 1)
                ->assertJson([
                    'message' => 'Too many terminal commands are already running on this server. Please retry shortly.',
                    'retry_after' => 1,
                ]);
        } finally {
            $locks->each->release();
        }

        Process::assertNotRan(fn ($process) => str($process->command)->contains('docker exec'));
    });

    test('selects only the requested pull request container', function () {
        Process::fake([
            '*docker ps*' => Process::result(output: dockerPsApplicationContainerOutput('base').dockerPsApplicationContainerOutput('preview-123', pullRequestId: 123).dockerPsApplicationContainerOutput('preview-456', pullRequestId: 456)),
            '*docker exec*' => Process::result(output: "ok\n"),
        ]);

        $this->withHeaders(applicationTerminalAuthHeaders($this->bearerToken))
            ->postJson("/api/v1/applications/{$this->application->uuid}/exec", [
                'command' => 'whoami',
                'pull_request_id' => 123,
            ])
            ->assertOk();

        Process::assertRan(fn ($process) => str($process->command)->contains("docker exec 'preview-123'"));
    });

    test('selects only the base deployment when pull request id is zero', function () {
        Process::fake([
            '*docker ps*' => Process::result(output: dockerPsApplicationContainerOutput('base').dockerPsApplicationContainerOutput('preview-123', pullRequestId: 123)),
            '*docker exec*' => Process::result(output: "ok\n"),
        ]);

        $this->withHeaders(applicationTerminalAuthHeaders($this->bearerToken))
            ->postJson("/api/v1/applications/{$this->application->uuid}/exec", [
                'command' => 'whoami',
                'pull_request_id' => 0,
            ])
            ->assertOk();

        Process::assertRan(fn ($process) => str($process->command)->contains("docker exec 'base'"));
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

    test('requires a server when the requested container exists on multiple servers', function () {
        $additionalServer = Server::factory()->create([
            'team_id' => $this->team->id,
            'private_key_id' => $this->privateKey->id,
            'user' => 'root',
        ]);
        $additionalServer->settings()->update([
            'is_terminal_enabled' => true,
            'force_disabled' => false,
        ]);
        $additionalDestination = StandaloneDocker::factory()->create([
            'server_id' => $additionalServer->id,
            'network' => 'coolify-additional-test',
        ]);
        $this->application->additional_servers()->attach($additionalServer->id, [
            'standalone_docker_id' => $additionalDestination->id,
        ]);

        Process::fake([
            '*docker ps*' => Process::result(output: dockerPsApplicationContainerOutput('app-container')),
        ]);

        $response = $this->withHeaders(applicationTerminalAuthHeaders($this->bearerToken))
            ->postJson("/api/v1/applications/{$this->application->uuid}/exec", [
                'command' => 'whoami',
                'container' => 'app-container',
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Multiple servers contain this container. Specify a server_uuid.');
        $response->assertJsonCount(2, 'containers');
        $response->assertJsonPath('containers.0.container', 'app-container');
        $response->assertJsonPath('containers.1.container', 'app-container');
        Process::assertNotRan(fn ($process) => str($process->command)->contains('docker exec'));
    });
});
