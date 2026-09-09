<?php

use App\Jobs\V5RotateAgentTokenJob;
use App\Jobs\V5RotateAgentTokensJob;
use App\Models\V5\Server as V5Server;
use App\Services\Flux\AgentTokenIssuer;
use App\Services\Flux\FluxClient;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

/**
 * @return array{0: string, 1: string}
 */
function createRotationJwtKeypair(): array
{
    $directory = storage_path('framework/testing/rotation-keys-'.bin2hex(random_bytes(4)));
    mkdir($directory, 0777, true);

    $privateKeyPath = $directory.'/jwt.priv';
    $publicKeyPath = $directory.'/jwt.pub';

    exec('openssl genpkey -algorithm EC -pkeyopt ec_paramgen_curve:P-256 -out '.escapeshellarg($privateKeyPath), $out, $code);
    expect($code)->toBe(0);
    chmod($privateKeyPath, 0600);

    exec('openssl pkey -in '.escapeshellarg($privateKeyPath).' -pubout -out '.escapeshellarg($publicKeyPath), $out, $code);
    expect($code)->toBe(0);

    return [$privateKeyPath, $publicKeyPath];
}

beforeEach(function () {
    resetV5DashboardTestState();
    createSharedUserAndTeamTables();

    [$this->user, $this->team] = createV5UserWithTeam();

    [$this->privateKeyPath, $this->publicKeyPath] = createRotationJwtKeypair();
    Config::set('flux.jwt_private_key_path', $this->privateKeyPath);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function createRotationServer(array $attributes = []): V5Server
{
    static $counter = 0;
    $counter++;

    return V5Server::query()->create([
        'team_id' => test()->team->id,
        'created_by_user_id' => test()->user->id,
        'name' => 'edge-'.$counter,
        'host' => '203.0.113.'.$counter,
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => ['coold'],
        'wireguard_management_ip' => '100.64.0.'.$counter,
        'last_bootstrapped_at' => now(),
        ...$attributes,
    ]);
}

it('issueForServer persists agent_token_expires_at approximately now plus the ttl', function () {
    Config::set('flux.host_token_ttl', 3600);

    $server = createRotationServer();

    app(AgentTokenIssuer::class)->issueForServer($server);

    $server->refresh();

    expect($server->agent_token_jti)->toBeString()->not->toBe('')
        ->and($server->agent_token_expires_at)->not->toBeNull()
        ->and($server->agent_token_expires_at->timestamp)
        ->toBeGreaterThanOrEqual(now()->addSeconds(3600)->subSeconds(30)->timestamp)
        ->toBeLessThanOrEqual(now()->addSeconds(3600)->addSeconds(30)->timestamp);
});

it('dispatches rotation only for eligible servers whose token is missing or near expiry', function () {
    Config::set('flux.host_token_refresh_threshold', 43200);
    Queue::fake();

    $neverIssued = createRotationServer(['agent_token_expires_at' => null]);
    $expiringSoon = createRotationServer(['agent_token_expires_at' => now()->addHours(6)]);
    $stillFresh = createRotationServer(['agent_token_expires_at' => now()->addHours(20)]);
    $notInstalled = createRotationServer(['status' => 'added', 'agent_token_expires_at' => null]);
    $withoutCoold = createRotationServer(['capabilities' => [], 'agent_token_expires_at' => null]);
    $notBootstrapped = createRotationServer(['last_bootstrapped_at' => null, 'agent_token_expires_at' => null]);

    (new V5RotateAgentTokensJob)->handle();

    Queue::assertPushed(V5RotateAgentTokenJob::class, 2);
    Queue::assertPushed(V5RotateAgentTokenJob::class, fn (V5RotateAgentTokenJob $job) => $job->serverId === $neverIssued->id);
    Queue::assertPushed(V5RotateAgentTokenJob::class, fn (V5RotateAgentTokenJob $job) => $job->serverId === $expiringSoon->id);
    Queue::assertNotPushed(V5RotateAgentTokenJob::class, fn (V5RotateAgentTokenJob $job) => $job->serverId === $stillFresh->id);
    Queue::assertNotPushed(V5RotateAgentTokenJob::class, fn (V5RotateAgentTokenJob $job) => $job->serverId === $notInstalled->id);
    Queue::assertNotPushed(V5RotateAgentTokenJob::class, fn (V5RotateAgentTokenJob $job) => $job->serverId === $withoutCoold->id);
    Queue::assertNotPushed(V5RotateAgentTokenJob::class, fn (V5RotateAgentTokenJob $job) => $job->serverId === $notBootstrapped->id);
});

it('delivers the fresh token over RPC and never touches SSH on RPC success', function () {
    Config::set('flux.host_token_ttl', 86400);
    Process::fake();
    Log::spy();

    $server = createRotationServer([
        'agent_token_jti' => 'old-jti',
        'agent_token_expires_at' => now()->addHour(),
    ]);
    $privateKey = createV5PrivateKey($server->team, 'Rotation Key');
    $server->update(['private_key_id' => $privateKey->id]);

    $flux = Mockery::mock(FluxClient::class);
    $flux->shouldReceive('pushHostToken')
        ->once()
        ->with($server->fluxHostId(), Mockery::type('string'));
    app()->instance(FluxClient::class, $flux);

    (new V5RotateAgentTokenJob($server->id))->handle();

    $server->refresh();

    expect($server->agent_token_jti)->not->toBe('old-jti')->toBeString()->not->toBe('')
        ->and($server->agent_token_expires_at->timestamp)
        ->toBeGreaterThan(now()->addHours(20)->timestamp);

    Process::assertNothingRan();
    Log::shouldHaveReceived('debug')->withArgs(
        fn (string $message, array $context = []) => ($context['delivery'] ?? null) === 'rpc'
    );
});

it('falls back to the SSH push when RPC delivery fails and advances jti on SSH success', function () {
    Config::set('flux.host_token_ttl', 86400);
    Process::fake(['*' => Process::result(output: '')]);
    Log::spy();

    $server = createRotationServer([
        'agent_token_jti' => 'old-jti',
        'agent_token_expires_at' => now()->addHour(),
    ]);
    $privateKey = createV5PrivateKey($server->team, 'Rotation Key');
    $server->update(['private_key_id' => $privateKey->id]);

    $flux = Mockery::mock(FluxClient::class);
    $flux->shouldReceive('pushHostToken')
        ->once()
        ->andThrow(new RuntimeException('host is not connected'));
    app()->instance(FluxClient::class, $flux);

    (new V5RotateAgentTokenJob($server->id))->handle();

    $server->refresh();

    expect($server->agent_token_jti)->not->toBe('old-jti')->toBeString()->not->toBe('')
        ->and($server->agent_token_expires_at->timestamp)
        ->toBeGreaterThan(now()->addHours(20)->timestamp);

    Process::assertRan(function ($process): bool {
        $command = $process->command;

        if (! is_array($command) || ($command[0] ?? null) !== 'ssh') {
            return false;
        }

        $script = end($command);

        return str_contains($script, '/etc/coolify/host-jwt')
            && str_contains($script, 'chmod 600 /etc/coolify/host-jwt')
            && str_contains($script, 'tee /etc/coolify/host-jwt');
    });
    Log::shouldHaveReceived('debug')->withArgs(
        fn (string $message, array $context = []) => ($context['delivery'] ?? null) === 'ssh'
    );
});

it('leaves jti and expires_at unchanged when both RPC and SSH delivery fail', function () {
    Config::set('flux.host_token_ttl', 86400);
    Process::fake(['*' => Process::result(errorOutput: 'connection refused', exitCode: 255)]);

    $expiresAt = now()->addHour();
    $server = createRotationServer([
        'agent_token_jti' => 'old-jti',
        'agent_token_expires_at' => $expiresAt,
    ]);
    $privateKey = createV5PrivateKey($server->team, 'Rotation Key');
    $server->update(['private_key_id' => $privateKey->id]);

    $flux = Mockery::mock(FluxClient::class);
    $flux->shouldReceive('pushHostToken')
        ->once()
        ->andThrow(new RuntimeException('host is not connected'));
    app()->instance(FluxClient::class, $flux);

    (new V5RotateAgentTokenJob($server->id))->handle();

    $server->refresh();

    expect($server->agent_token_jti)->toBe('old-jti')
        ->and($server->agent_token_expires_at->timestamp)->toBe($expiresAt->timestamp);
});

it('pushHostToken dispatches the host.jwt.set command with the jwt for the target host', function () {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl is required to fake a Flux Unix socket.');
    }

    $responseBody = json_encode([
        'request_id' => 'test-request',
        'status' => 'ok',
        'data' => [],
    ], JSON_THROW_ON_ERROR);

    $captured = withCapturingFluxSocket(
        "HTTP/1.1 200 OK\r\n".
        "Content-Type: application/json\r\n".
        'Content-Length: '.strlen($responseBody)."\r\n".
        "\r\n".
        $responseBody,
        function (): void {
            (new FluxClient)->pushHostToken('100.64.0.42', 'the-new-host-jwt');
        }
    );

    $position = strpos($captured, "\r\n\r\n");
    $requestBody = $position === false ? '' : substr($captured, $position + 4);
    $decoded = json_decode($requestBody, true);

    expect($decoded)->toBeArray()
        ->and($decoded['host_id'])->toBe('100.64.0.42')
        ->and($decoded['command'])->toBe(['type' => 'host.jwt.set', 'jwt' => 'the-new-host-jwt']);
});

it('revokeToken posts the jti and expires_at to the flux revocation endpoint', function () {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl is required to fake a Flux Unix socket.');
    }

    $responseBody = json_encode(['revoked' => 'the-jti'], JSON_THROW_ON_ERROR);

    $captured = withCapturingFluxSocket(
        "HTTP/1.1 200 OK\r\n".
        "Content-Type: application/json\r\n".
        'Content-Length: '.strlen($responseBody)."\r\n".
        "\r\n".
        $responseBody,
        function (): void {
            (new FluxClient)->revokeToken('the-jti', 1893456000);
        }
    );

    $requestLine = strtok($captured, "\r\n");
    $position = strpos($captured, "\r\n\r\n");
    $requestBody = $position === false ? '' : substr($captured, $position + 4);
    $decoded = json_decode($requestBody, true);

    expect($requestLine)->toBe('POST /v1/tokens/revoke HTTP/1.1')
        ->and($decoded)->toBe(['jti' => 'the-jti', 'expires_at' => 1893456000]);
});

it('revokeToken omits expires_at from the body when it is null', function () {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl is required to fake a Flux Unix socket.');
    }

    $responseBody = json_encode(['revoked' => 'the-jti'], JSON_THROW_ON_ERROR);

    $captured = withCapturingFluxSocket(
        "HTTP/1.1 200 OK\r\n".
        "Content-Type: application/json\r\n".
        'Content-Length: '.strlen($responseBody)."\r\n".
        "\r\n".
        $responseBody,
        function (): void {
            (new FluxClient)->revokeToken('the-jti');
        }
    );

    $position = strpos($captured, "\r\n\r\n");
    $requestBody = $position === false ? '' : substr($captured, $position + 4);

    expect(json_decode($requestBody, true))->toBe(['jti' => 'the-jti']);
});

it('runs the rotation jobs on the dedicated v5-reconcile queue', function () {
    expect((new V5RotateAgentTokensJob)->queue)->toBe('v5-reconcile')
        ->and((new V5RotateAgentTokenJob(1))->queue)->toBe('v5-reconcile');
});

/**
 * Runs $callback against a fake Flux Unix socket that records the full HTTP
 * request (headers + body) it receives, then returns the captured raw request
 * so the wire command can be asserted.
 */
function withCapturingFluxSocket(string $response, Closure $callback): string
{
    $directory = storage_path('framework/testing');

    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    $socketPath = $directory.'/flux-'.bin2hex(random_bytes(8)).'.sock';
    $capturePath = $directory.'/flux-capture-'.bin2hex(random_bytes(8)).'.txt';
    $socketServer = stream_socket_server("unix://{$socketPath}", $errorCode, $errorMessage);

    expect($socketServer)->not->toBeFalse("Could not create fake Flux socket: {$errorMessage} ({$errorCode})");

    $pid = pcntl_fork();

    if ($pid === 0) {
        $connection = stream_socket_accept($socketServer, 5);

        if ($connection !== false) {
            $request = '';

            while (! str_contains($request, "\r\n\r\n") && ! feof($connection)) {
                $request .= fread($connection, 8192);
            }

            if (preg_match('/Content-Length:\s*(\d+)/i', $request, $matches) === 1) {
                $headerEnd = strpos($request, "\r\n\r\n") + 4;
                $needed = (int) $matches[1];

                while (strlen($request) - $headerEnd < $needed && ! feof($connection)) {
                    $chunk = fread($connection, 8192);

                    if ($chunk === '' || $chunk === false) {
                        break;
                    }

                    $request .= $chunk;
                }
            }

            file_put_contents($capturePath, $request);
            fwrite($connection, $response);
            fclose($connection);
        }

        fclose($socketServer);
        exit(0);
    }

    fclose($socketServer);
    Config::set('flux.unix_socket_path', $socketPath);
    Config::set('flux.connection_timeout_seconds', 1.0);
    Config::set('flux.dispatch_timeout_seconds', 1.0);

    try {
        $callback();
    } finally {
        pcntl_waitpid($pid, $status);
    }

    $captured = is_file($capturePath) ? (string) file_get_contents($capturePath) : '';

    @unlink($socketPath);
    @unlink($capturePath);

    return $captured;
}
