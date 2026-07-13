<?php

use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
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

    $this->bearerToken = createServerTerminalApiToken($this->user, $this->team, ['terminal']);

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
            '*' => Process::result(output: "hello\n", errorOutput: "warning\n", exitCode: 0),
        ]);

        $response = $this->withHeaders(serverTerminalAuthHeaders($this->bearerToken))
            ->postJson("/api/v1/servers/{$this->server->uuid}/exec", [
                'command' => 'echo hello',
                'timeout' => 10,
            ]);

        $response->assertOk();
        $response->assertJson([
            'exit_code' => 0,
            'stdout' => 'hello',
            'stderr' => 'warning',
        ]);
    });

    test('requires terminal token ability', function () {
        $deployToken = createServerTerminalApiToken($this->user, $this->team, ['deploy']);

        $response = $this->withHeaders(serverTerminalAuthHeaders($deployToken))
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

    test('rejects timeouts over ten seconds', function () {
        $response = $this->withHeaders(serverTerminalAuthHeaders($this->bearerToken))
            ->postJson("/api/v1/servers/{$this->server->uuid}/exec", [
                'command' => 'whoami',
                'timeout' => 11,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('timeout');
    });

    test('rate limits terminal commands per server', function () {
        $this->withoutMiddleware(ThrottleRequests::class);

        Process::fake([
            '*' => Process::result(output: 'ok', exitCode: 0),
        ]);

        $tokens = collect(range(1, 21))
            ->map(fn () => createServerTerminalApiToken($this->user, $this->team, ['terminal']));

        foreach ($tokens->take(20) as $token) {
            $this->withHeaders(serverTerminalAuthHeaders($token))
                ->postJson("/api/v1/servers/{$this->server->uuid}/exec", [
                    'command' => 'whoami',
                ])
                ->assertOk();
        }

        $this->withHeaders(serverTerminalAuthHeaders($tokens->last()))
            ->postJson("/api/v1/servers/{$this->server->uuid}/exec", [
                'command' => 'whoami',
            ])
            ->assertStatus(429)
            ->assertJsonPath('message', fn (string $message) => str($message)->startsWith('Too many terminal commands for this server.'));
    });

    test('rate limits terminal commands per token and team', function () {
        Process::fake([
            '*' => Process::result(output: 'ok', exitCode: 0),
        ]);

        $servers = collect([$this->server]);
        for ($i = 0; $i < 10; $i++) {
            $server = Server::factory()->create([
                'team_id' => $this->team->id,
                'private_key_id' => $this->privateKey->id,
                'ip' => "coolify-testing-host-{$i}",
                'user' => 'root',
            ]);
            $server->settings()->update([
                'is_terminal_enabled' => true,
                'force_disabled' => false,
            ]);
            $servers->push($server);
        }

        foreach ($servers->take(10) as $server) {
            $this->withHeaders(serverTerminalAuthHeaders($this->bearerToken))
                ->postJson("/api/v1/servers/{$server->uuid}/exec", [
                    'command' => 'whoami',
                ])
                ->assertOk();
        }

        $this->withHeaders(serverTerminalAuthHeaders($this->bearerToken))
            ->postJson("/api/v1/servers/{$servers->last()->uuid}/exec", [
                'command' => 'whoami',
            ])
            ->assertStatus(429)
            ->assertJsonPath('message', fn (string $message) => str($message)->startsWith('Too many terminal command requests. Please retry in '))
            ->assertJsonPath('retry_after', fn (int $retryAfter) => $retryAfter > 0);
    });
});
