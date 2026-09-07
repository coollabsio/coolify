<?php

use App\Actions\V5\Proxy\StartCaddyIngress;
use App\Actions\V5\Proxy\StopCaddyIngress;
use App\Exceptions\V5\UnsupportedCooldVerb;
use App\Models\V5\Server;
use App\Services\Flux\FluxClient;
use App\Support\V5\ConnectionFirewallSync;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\Support\V5TestSchema;
use Tests\TestCase;

uses(TestCase::class);

it('detects unsupported coold verbs from flux http 501 responses', function () {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl is required to fake a Flux Unix socket.');
    }

    // Flux rejects verbs the node's coold did not advertise with HTTP 501 and
    // "primitive <verb> is not supported by host" (coold repo:
    // flux/src/routing.rs:50-53, flux/src/unix_bridge.rs:227-245).
    $body = json_encode([
        'request_id' => 'test-request',
        'status' => 'error',
        'code' => 501,
        'message' => 'primitive firewall.allow is not supported by host',
    ], JSON_THROW_ON_ERROR);

    $caught = null;

    withCooldVerbContractFluxSocket(
        "HTTP/1.1 501 Not Implemented\r\n".
        "Content-Type: application/json\r\n".
        'Content-Length: '.strlen($body)."\r\n".
        "\r\n".
        $body,
        function () use (&$caught): void {
            try {
                (new FluxClient)->applyFirewallRule('100.64.0.10', [
                    'id' => 'rule-1',
                    'namespace' => 'default',
                    'src' => '0.0.0.0/0',
                    'dst' => 'coolify-v5-caddy',
                    'proto' => 'tcp',
                    'port' => 80,
                ]);
            } catch (RuntimeException $exception) {
                $caught = $exception;
            }
        }
    );

    expect($caught)->toBeInstanceOf(UnsupportedCooldVerb::class)
        ->and($caught->verb)->toBe('firewall.allow')
        ->and($caught->getMessage())->toBe('primitive firewall.allow is not supported by host');
});

it('detects unsupported coold verbs from the flux message even without a 501 status', function () {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl is required to fake a Flux Unix socket.');
    }

    $body = json_encode([
        'request_id' => 'test-request',
        'status' => 'error',
        'code' => 502,
        'message' => 'primitive coold.logs is not supported by host',
    ], JSON_THROW_ON_ERROR);

    $caught = null;

    withCooldVerbContractFluxSocket(
        "HTTP/1.1 502 Bad Gateway\r\n".
        "Content-Type: application/json\r\n".
        'Content-Length: '.strlen($body)."\r\n".
        "\r\n".
        $body,
        function () use (&$caught): void {
            try {
                (new FluxClient)->cooldLogs('100.64.0.10');
            } catch (RuntimeException $exception) {
                $caught = $exception;
            }
        }
    );

    expect($caught)->toBeInstanceOf(UnsupportedCooldVerb::class)
        ->and($caught->verb)->toBe('coold.logs');
});

it('keeps generic flux dispatch failures as plain runtime exceptions', function () {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl is required to fake a Flux Unix socket.');
    }

    $body = json_encode([
        'request_id' => 'test-request',
        'status' => 'error',
        'code' => 500,
        'message' => 'apply firewall rule: nft exited with status 1',
    ], JSON_THROW_ON_ERROR);

    $caught = null;

    withCooldVerbContractFluxSocket(
        "HTTP/1.1 500 Internal Server Error\r\n".
        "Content-Type: application/json\r\n".
        'Content-Length: '.strlen($body)."\r\n".
        "\r\n".
        $body,
        function () use (&$caught): void {
            try {
                (new FluxClient)->applyFirewallRule('100.64.0.10', [
                    'id' => 'rule-1',
                    'namespace' => 'default',
                    'src' => '0.0.0.0/0',
                    'dst' => 'coolify-v5-caddy',
                    'proto' => 'tcp',
                    'port' => 80,
                ]);
            } catch (RuntimeException $exception) {
                $caught = $exception;
            }
        }
    );

    expect($caught)->toBeInstanceOf(RuntimeException::class)
        ->not->toBeInstanceOf(UnsupportedCooldVerb::class)
        ->and($caught->getMessage())->toBe('apply firewall rule: nft exited with status 1');
});

it('marks caddy ingress running with a warning when the node coold lacks firewall support', function () {
    Event::fake();

    Schema::dropIfExists('v5_applications');
    Schema::dropIfExists('v5_servers');
    V5TestSchema::createServersTable();
    V5TestSchema::createApplicationsTable();

    $server = Server::query()->create([
        'uuid' => 'cooldverb-ingress-server-uuid',
        'team_id' => 1,
        'created_by_user_id' => 1,
        'name' => 'ingress-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'capabilities' => ['ingress'],
        'uuid' => 'test-server-uuid',
        'wireguard_management_ip' => '100.64.0.10',
    ]);

    $fluxClient = Mockery::mock(FluxClient::class);
    $fluxClient
        ->shouldReceive('applyIngress')
        ->once()
        ->andReturn('Caddy ingress applied.');
    $fluxClient
        ->shouldReceive('applyFirewallRule')
        ->once()
        ->andThrow(new UnsupportedCooldVerb('firewall.allow', 'primitive firewall.allow is not supported by host'));
    app()->instance(FluxClient::class, $fluxClient);

    $result = StartCaddyIngress::run($server);

    $server->refresh();

    expect($result)->toBe('Caddy ingress applied.')
        ->and($server->ingress_type)->toBe('caddy')
        ->and($server->ingress_status)->toBe('running')
        ->and($server->last_status_check)->toBe('flux')
        ->and($server->last_status_output)->toContain('does not support firewall.allow');

    Schema::dropIfExists('v5_applications');
    Schema::dropIfExists('v5_servers');
});

it('still surfaces supported-but-failed firewall errors when starting caddy ingress', function () {
    $server = new Server([
        'uuid' => 'test-server-uuid',
        'wireguard_management_ip' => '100.64.0.10',
        'capabilities' => ['ingress'],
    ]);

    $fluxClient = Mockery::mock(FluxClient::class);
    $fluxClient
        ->shouldReceive('applyIngress')
        ->once()
        ->andReturn('Caddy ingress applied.');
    $fluxClient
        ->shouldReceive('applyFirewallRule')
        ->once()
        ->andThrow(new RuntimeException('apply firewall rule: nft exited with status 1'));
    app()->instance(FluxClient::class, $fluxClient);

    expect(fn () => StartCaddyIngress::run($server))
        ->toThrow(RuntimeException::class, 'apply firewall rule: nft exited with status 1');
});

it('stops caddy ingress even when the node coold lacks firewall support', function () {
    $server = new Server([
        'uuid' => 'test-server-uuid',
        'wireguard_management_ip' => '100.64.0.10',
        'capabilities' => ['ingress'],
    ]);

    $fluxClient = Mockery::mock(FluxClient::class);
    $fluxClient
        ->shouldReceive('revokeFirewallRule')
        ->once()
        ->andThrow(new UnsupportedCooldVerb('firewall.revoke', 'primitive firewall.revoke is not supported by host'));
    $fluxClient
        ->shouldReceive('stopIngress')
        ->once()
        ->with(Mockery::type('string'), 'caddy')
        ->andReturn('Caddy ingress stopped.');
    app()->instance(FluxClient::class, $fluxClient);

    expect(StopCaddyIngress::run($server))->toBe('Caddy ingress stopped.');
});

it('skips resource connection firewall sync rules when the node coold lacks firewall support', function () {
    $sync = new ConnectionFirewallSync;

    $oldRule = [
        'id' => 'v5-resource-connection:1:1:2:tcp:5432',
        'hostId' => '100.64.0.11',
        'rule' => [
            'id' => 'v5-resource-connection:1:1:2:tcp:5432',
            'namespace' => 'default',
            'src' => 'coolify-v5-api',
            'dst' => 'coolify-v5-postgres',
            'proto' => 'tcp',
            'port' => 5432,
        ],
    ];
    $newRule = [
        'id' => 'v5-resource-connection:1:1:2:tcp:6379',
        'hostId' => '100.64.0.10',
        'rule' => [
            'id' => 'v5-resource-connection:1:1:2:tcp:6379',
            'namespace' => 'default',
            'src' => 'coolify-v5-api',
            'dst' => 'coolify-v5-redis',
            'proto' => 'tcp',
            'port' => 6379,
        ],
    ];

    $fluxClient = Mockery::mock(FluxClient::class);
    $fluxClient
        ->shouldReceive('revokeFirewallRule')
        ->once()
        ->with(Mockery::type('string'), $oldRule['id'])
        ->andThrow(new UnsupportedCooldVerb('firewall.revoke', 'primitive firewall.revoke is not supported by host'));
    $fluxClient
        ->shouldReceive('applyFirewallRule')
        ->once()
        ->with(Mockery::type('string'), $newRule['rule'])
        ->andThrow(new UnsupportedCooldVerb('firewall.allow', 'primitive firewall.allow is not supported by host'));

    $sync->sync($fluxClient, collect([$oldRule]), collect([$newRule]));

    expect(true)->toBeTrue();
});

function withCooldVerbContractFluxSocket(string $response, Closure $callback): void
{
    $directory = storage_path('framework/testing');

    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    $socketPath = $directory.'/flux-'.bin2hex(random_bytes(8)).'.sock';
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
        @unlink($socketPath);
    }
}
