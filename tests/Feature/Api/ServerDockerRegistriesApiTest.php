<?php

use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    config([
        'cache.default' => 'array',
        'session.driver' => 'array',
        'app.maintenance.driver' => 'file',
        'constants.ssh.mux_enabled' => false,
    ]);

    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(
        ['id' => 0],
        ['is_api_enabled' => true],
    ));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $this->team->id])->id,
    ]);
    $this->server->settings->update(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false]);
    $this->server->refresh();

    $this->writeToken = serverRegistriesApiToken($this->user, $this->team, ['read', 'write']);
    $this->sensitiveToken = serverRegistriesApiToken($this->user, $this->team, ['read', 'read:sensitive']);
});

function serverRegistriesApiToken(User $user, Team $team, array $abilities): string
{
    $plainTextToken = Str::random(40);
    $token = $user->tokens()->create([
        'name' => 'server-registries-api-test-'.Str::random(6),
        'token' => hash('sha256', $plainTextToken),
        'abilities' => $abilities,
        'team_id' => $team->id,
    ]);

    return $token->getKey().'|'.$plainTextToken;
}

function serverRegistriesApiHeaders(string $bearerToken): array
{
    return [
        'Authorization' => 'Bearer '.$bearerToken,
        'Content-Type' => 'application/json',
    ];
}

/**
 * Record every SSH command and answer the Docker config read with the given config.
 *
 * @param  array<int, string>  $commands
 */
function fakeServerRegistriesSsh(array &$commands, string $config = '', string $error = ''): void
{
    Process::fake(function ($process) use (&$commands, $config, $error) {
        $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;
        $commands[] = $command;

        if (str_contains($command, '.docker/config.json')) {
            return Process::result(output: $config);
        }

        return $error !== '' ? Process::result(errorOutput: $error, exitCode: 1) : Process::result(output: '');
    });
}

test('GET /api/v1/servers/{uuid}/registries lists registry logins without secrets', function () {
    $environment = Environment::factory()->create([
        'project_id' => Project::factory()->create(['team_id' => $this->team->id])->id,
    ]);
    $destination = $this->server->standaloneDockers()->firstOrFail();
    $application = Application::factory()->create([
        'name' => 'private-app',
        'docker_registry_image_name' => 'registry.example.com:5000/team/app',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    $commands = [];
    fakeServerRegistriesSsh($commands, json_encode([
        'auths' => ['ghcr.io' => ['auth' => base64_encode('octocat:SECRET_TOKEN')]],
    ]));

    $response = $this->withHeaders(serverRegistriesApiHeaders($this->sensitiveToken))
        ->getJson("/api/v1/servers/{$this->server->uuid}/registries")
        ->assertOk()
        ->assertExactJson([
            'registries' => [
                ['registry' => 'ghcr.io', 'logged_in' => true, 'source' => 'auths', 'username' => 'octocat', 'used_by' => []],
                [
                    'registry' => 'registry.example.com:5000',
                    'logged_in' => false,
                    'source' => null,
                    'username' => null,
                    'used_by' => [['type' => 'Application', 'name' => 'private-app', 'link' => $application->link()]],
                ],
            ],
            'error' => null,
        ]);

    expect($response->getContent())
        ->not->toContain('SECRET_TOKEN')
        ->not->toContain(base64_encode('octocat:SECRET_TOKEN'));
});

test('GET /api/v1/servers/{uuid}/registries returns an error without SSH when the server is not reachable', function () {
    $this->server->settings->update(['is_reachable' => false]);
    Process::fake();

    $this->withHeaders(serverRegistriesApiHeaders($this->sensitiveToken))
        ->getJson("/api/v1/servers/{$this->server->uuid}/registries")
        ->assertOk()
        ->assertJsonPath('registries', [])
        ->assertJsonPath('error', 'The server is not reachable. Validate the server to read its registry logins.');

    Process::assertNothingRan();
});

test('GET /api/v1/servers/{uuid}/registries requires read:sensitive', function () {
    Process::fake();
    $readToken = serverRegistriesApiToken($this->user, $this->team, ['read']);

    $this->withHeaders(serverRegistriesApiHeaders($readToken))
        ->getJson("/api/v1/servers/{$this->server->uuid}/registries")
        ->assertForbidden();

    Process::assertNothingRan();
});

test('POST /api/v1/servers/{uuid}/registries logs in through stdin', function () {
    $commands = [];
    fakeServerRegistriesSsh($commands);
    $token = "ghp_SECRET'token\"with\$special";

    $response = $this->withHeaders(serverRegistriesApiHeaders($this->writeToken))
        ->postJson("/api/v1/servers/{$this->server->uuid}/registries", [
            'registry' => 'https://GHCR.io/',
            'username' => 'octocat',
            'password' => $token,
        ])
        ->assertOk()
        ->assertExactJson(['message' => 'Logged in to ghcr.io.']);

    expect($response->getContent())->not->toContain('ghp_SECRET');

    $login = collect($commands)->first(fn (string $command) => str_contains($command, ' login '));
    expect($login)
        ->toContain("login --username 'octocat' --password-stdin 'ghcr.io'")
        ->not->toContain('ghp_SECRET');

    $event = AuditEvent::query()->where('event', 'api.server.registry_login')->sole();
    expect($event->metadata['registry'])->toBe('ghcr.io')
        ->and($event->metadata['outcome'])->toBe('success')
        ->and($event->metadata['server_uuid'])->toBe($this->server->uuid)
        ->and(json_encode($event->metadata))->not->toContain('ghp_SECRET');
    expect(AuditEvent::query()->where('event', 'like', 'ui.%')->exists())->toBeFalse();
});

test('POST /api/v1/servers/{uuid}/registries validates the input before running a command', function (array $payload, string $errorField) {
    Process::fake();

    $this->withHeaders(serverRegistriesApiHeaders($this->writeToken))
        ->postJson("/api/v1/servers/{$this->server->uuid}/registries", $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($errorField);

    Process::assertNothingRan();
})->with([
    'invalid registry' => [['registry' => 'bad host;rm', 'username' => 'octocat', 'password' => 'secret'], 'registry'],
    'missing registry' => [['username' => 'octocat', 'password' => 'secret'], 'registry'],
    'username with spaces' => [['registry' => 'ghcr.io', 'username' => 'octo cat', 'password' => 'secret'], 'username'],
    'missing password' => [['registry' => 'ghcr.io', 'username' => 'octocat'], 'password'],
    'too long password' => [['registry' => 'ghcr.io', 'username' => 'octocat', 'password' => str_repeat('a', 20001)], 'password'],
    'unknown field' => [['registry' => 'ghcr.io', 'username' => 'octocat', 'password' => 'secret', 'extra' => 'x'], 'extra'],
]);

test('POST /api/v1/servers/{uuid}/registries returns the docker error with the token masked', function () {
    $commands = [];
    fakeServerRegistriesSsh($commands, error: "WARNING! Using --password via the CLI is insecure.\nError response from daemon: unauthorized: bad token SECRET_TOKEN");

    $response = $this->withHeaders(serverRegistriesApiHeaders($this->writeToken))
        ->postJson("/api/v1/servers/{$this->server->uuid}/registries", [
            'registry' => 'ghcr.io',
            'username' => 'octocat',
            'password' => 'SECRET_TOKEN',
        ])
        ->assertStatus(400)
        ->assertExactJson(['message' => 'Login failed: Error response from daemon: unauthorized: bad token ***']);

    expect($response->getContent())->not->toContain('SECRET_TOKEN');
    expect(AuditEvent::query()->where('event', 'api.server.registry_login')->sole()->metadata['outcome'])->toBe('failed');
});

test('POST /api/v1/servers/{uuid}/registries/{registry}/check checks the saved login', function () {
    $commands = [];
    fakeServerRegistriesSsh($commands);

    $this->withHeaders(serverRegistriesApiHeaders($this->writeToken))
        ->postJson("/api/v1/servers/{$this->server->uuid}/registries/registry.example.com:5000/check")
        ->assertOk()
        ->assertExactJson(['message' => 'The login for registry.example.com:5000 works.']);

    expect(collect($commands)->first(fn (string $command) => str_contains($command, ' login')))
        ->toContain("login 'registry.example.com:5000' </dev/null");
});

test('POST /api/v1/servers/{uuid}/registries/{registry}/check returns the docker error', function () {
    $commands = [];
    fakeServerRegistriesSsh($commands, error: 'Error: Cannot perform an interactive login from a non TTY device');

    $this->withHeaders(serverRegistriesApiHeaders($this->writeToken))
        ->postJson("/api/v1/servers/{$this->server->uuid}/registries/ghcr.io/check")
        ->assertStatus(400)
        ->assertJsonPath('message', fn (string $message) => str_starts_with($message, 'Login check failed:'));
});

test('DELETE /api/v1/servers/{uuid}/registries/{registry} logs out', function () {
    $commands = [];
    fakeServerRegistriesSsh($commands);

    $this->withHeaders(serverRegistriesApiHeaders($this->writeToken))
        ->deleteJson("/api/v1/servers/{$this->server->uuid}/registries/ghcr.io")
        ->assertOk()
        ->assertExactJson(['message' => 'Logged out from ghcr.io.']);

    expect(collect($commands)->first(fn (string $command) => str_contains($command, ' logout')))
        ->toContain("logout 'ghcr.io'");
    $event = AuditEvent::query()->where('event', 'api.server.registry_logout')->sole();
    expect($event->metadata['registry'])->toBe('ghcr.io')
        ->and($event->metadata['outcome'])->toBe('success');
});

test('registry path parameters are validated before running a command', function (string $method, string $suffix) {
    Process::fake();

    $this->withHeaders(serverRegistriesApiHeaders($this->writeToken))
        ->json($method, "/api/v1/servers/{$this->server->uuid}/registries/{$suffix}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('registry');

    Process::assertNothingRan();
})->with([
    'check' => ['POST', 'bad%20host;rm/check'],
    'logout' => ['DELETE', 'bad%20host;rm'],
]);

test('mutating registry endpoints refuse unreachable servers without SSH', function () {
    $this->server->settings->update(['is_reachable' => false]);
    Process::fake();

    $this->withHeaders(serverRegistriesApiHeaders($this->writeToken))
        ->deleteJson("/api/v1/servers/{$this->server->uuid}/registries/ghcr.io")
        ->assertStatus(400);

    Process::assertNothingRan();
});

test('registry endpoints return 404 for a server of another team', function (string $method, string $suffix, array $payload) {
    Process::fake();
    $otherTeam = Team::factory()->create();
    $otherServer = Server::factory()->create([
        'team_id' => $otherTeam->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $otherTeam->id])->id,
    ]);
    $rootToken = serverRegistriesApiToken($this->user, $this->team, ['root']);

    $this->withHeaders(serverRegistriesApiHeaders($rootToken))
        ->json($method, "/api/v1/servers/{$otherServer->uuid}/registries{$suffix}", $payload)
        ->assertNotFound();

    Process::assertNothingRan();
})->with([
    'list' => ['GET', '', []],
    'login' => ['POST', '', ['registry' => 'ghcr.io', 'username' => 'octocat', 'password' => 'secret']],
    'check' => ['POST', '/ghcr.io/check', []],
    'logout' => ['DELETE', '/ghcr.io', []],
]);

test('registry endpoints that run commands require the write ability', function (string $method, string $suffix, array $payload) {
    Process::fake();
    $readToken = serverRegistriesApiToken($this->user, $this->team, ['read', 'read:sensitive']);

    $this->withHeaders(serverRegistriesApiHeaders($readToken))
        ->json($method, "/api/v1/servers/{$this->server->uuid}/registries{$suffix}", $payload)
        ->assertForbidden();

    Process::assertNothingRan();
})->with([
    'login' => ['POST', '', ['registry' => 'ghcr.io', 'username' => 'octocat', 'password' => 'secret']],
    'check' => ['POST', '/ghcr.io/check', []],
    'logout' => ['DELETE', '/ghcr.io', []],
]);

test('members cannot use registry endpoints', function () {
    Process::fake();
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);
    $memberReadToken = serverRegistriesApiToken($member, $this->team, ['read']);
    $memberSensitiveToken = serverRegistriesApiToken($member, $this->team, ['read', 'read:sensitive']);

    $this->withHeaders(serverRegistriesApiHeaders($memberReadToken))
        ->getJson("/api/v1/servers/{$this->server->uuid}/registries")
        ->assertForbidden();
    $this->withHeaders(serverRegistriesApiHeaders($memberSensitiveToken))
        ->getJson("/api/v1/servers/{$this->server->uuid}/registries")
        ->assertForbidden();

    Process::assertNothingRan();
});
