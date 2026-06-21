<?php

use App\Actions\V5\Proxy\GenerateCaddyIngressConfiguration;
use App\Actions\V5\Proxy\StartCaddyIngress;
use App\Actions\V5\Proxy\StopCaddyIngress;
use App\Models\V5\Application;
use App\Models\V5\ApplicationDomain;
use App\Models\V5\Server;
use App\Services\Flux\FluxClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

uses(TestCase::class);

it('generates a caddy ingress compose file with health endpoint and application routes', function () {
    $application = new Application([
        'name' => 'nginx-test',
        'container_name' => 'coolify-v5-nginx-test',
        'mesh_namespace' => 'default',
        'ingress_enabled' => true,
        'internal_port' => 8080,
    ]);
    $application->setRelation('domains', new Collection([
        new ApplicationDomain([
            'domain' => 'nginx.example.com',
        ]),
        new ApplicationDomain([
            'domain' => 'www.nginx.example.com',
        ]),
    ]));

    $configuration = GenerateCaddyIngressConfiguration::run(new Collection([$application]));

    expect($configuration['compose'])->toContain('container_name: coolify-v5-caddy')
        ->and($configuration['compose'])->toContain("image: 'docker.io/library/caddy:2-alpine'")
        ->and($configuration['compose'])->toContain('80:80')
        ->and($configuration['compose'])->not->toContain('443:443')
        ->and($configuration['compose'])->toContain('./Caddyfile:/etc/caddy/Caddyfile:ro')
        ->and($configuration['compose'])->toContain('./apps:/etc/caddy/apps:ro')
        ->and($configuration['caddyfile'])->toContain('respond /coolify-health 200')
        ->and($configuration['caddyfile'])->toContain('respond 404')
        ->and($configuration['caddyfile'])->toContain('import apps/*.caddy')
        ->and($configuration['apps'])->toHaveCount(1)
        ->and($configuration['apps'][0]['caddyfile'])->toContain('http://nginx.example.com {')
        ->and($configuration['apps'][0]['caddyfile'])->toContain('http://www.nginx.example.com {')
        ->and($configuration['apps'][0]['caddyfile'])->not->toContain('https://')
        ->and($configuration['apps'][0]['caddyfile'])->toContain('reverse_proxy coolify-v5-nginx-test.default.coolify.internal:8080');
});

it('does not generate app routes for applications without domains', function () {
    $application = new Application([
        'name' => 'private-app',
        'container_name' => 'coolify-v5-private',
        'mesh_namespace' => 'default',
    ]);
    $application->setRelation('domains', new Collection);

    $configuration = GenerateCaddyIngressConfiguration::run(new Collection([$application]));

    expect($configuration['caddyfile'])
        ->toContain('respond /coolify-health 200')
        ->and($configuration['apps'])->toBe([]);
});

it('does not generate app routes when ingress is disabled even with domains', function () {
    $application = new Application([
        'name' => 'private-app',
        'container_name' => 'coolify-v5-private',
        'mesh_namespace' => 'default',
        'ingress_enabled' => false,
        'internal_port' => 8080,
    ]);
    $application->setRelation('domains', new Collection([
        new ApplicationDomain([
            'domain' => 'private.example.com',
        ]),
    ]));

    $configuration = GenerateCaddyIngressConfiguration::run(new Collection([$application]));

    expect($configuration['apps'])->toBe([]);
});

it('does not generate app routes when the internal port is missing', function () {
    $application = new Application([
        'name' => 'needs-port',
        'container_name' => 'coolify-v5-needs-port',
        'mesh_namespace' => 'default',
        'ingress_enabled' => true,
        'internal_port' => null,
    ]);
    $application->setRelation('domains', new Collection([
        new ApplicationDomain([
            'domain' => 'needs-port.example.com',
        ]),
    ]));

    $configuration = GenerateCaddyIngressConfiguration::run(new Collection([$application]));

    expect($configuration['apps'])->toBe([]);
});

it('applies caddy ingress configuration through flux instead of ssh', function () {
    $server = new Server([
        'wireguard_management_ip' => '100.64.0.10',
        'node_address' => '10.0.0.10',
        'capabilities' => ['coold', 'ingress'],
    ]);

    $fluxClient = Mockery::mock(FluxClient::class);
    $fluxClient
        ->shouldReceive('applyCaddyIngress')
        ->once()
        ->with(
            '100.64.0.10',
            Mockery::on(fn (string $caddyfile): bool => str_contains($caddyfile, 'respond /coolify-health 200')),
            []
        )
        ->andReturn('Caddy ingress applied.');
    app()->instance(FluxClient::class, $fluxClient);

    $result = StartCaddyIngress::run($server);

    expect($result)->toBe('Caddy ingress applied.');
});

it('does not start caddy ingress for non-ingress servers', function () {
    $server = new Server([
        'capabilities' => ['coold'],
    ]);

    $result = StartCaddyIngress::run($server);

    expect($result)->toBe('Server is not an ingress server.');
});

it('stops caddy ingress through flux instead of ssh', function () {
    $server = new Server([
        'wireguard_management_ip' => '100.64.0.10',
        'node_address' => '10.0.0.10',
        'capabilities' => ['coold'],
    ]);

    $fluxClient = Mockery::mock(FluxClient::class);
    $fluxClient
        ->shouldReceive('stopCaddyIngress')
        ->once()
        ->with('100.64.0.10')
        ->andReturn('Caddy ingress stopped.');
    app()->instance(FluxClient::class, $fluxClient);

    $result = StopCaddyIngress::run($server);

    expect($result)->toBe('Caddy ingress stopped.');
});

it('includes flux error response details when dispatch returns a non success status', function () {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl is required to fake a Flux Unix socket.');
    }

    $body = json_encode([
        'request_id' => 'test-request',
        'status' => 'error',
        'code' => 500,
        'message' => 'start Caddy ingress: podman exited with status 125',
    ], JSON_THROW_ON_ERROR);

    withFakeFluxSocket(
        "HTTP/1.1 500 Internal Server Error\r\n".
        "Content-Type: application/json\r\n".
        'Content-Length: '.strlen($body)."\r\n".
        "\r\n".
        $body,
        fn () => (new FluxClient)->applyCaddyIngress('100.64.0.10', 'example.com { respond "ok" }')
    );
})->throws(RuntimeException::class, 'start Caddy ingress: podman exited with status 125');

it('uses a friendly message when flux returns an invalid http response', function () {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl is required to fake a Flux Unix socket.');
    }

    withFakeFluxSocket(
        '',
        fn () => (new FluxClient)->applyCaddyIngress('100.64.0.10', 'example.com { respond "ok" }')
    );
})->throws(RuntimeException::class, 'Flux did not return a response before the timeout.');

it('uses separate flux timeouts for health checks and command dispatches', function () {
    expect(config('flux.health_timeout_seconds'))->toBe(1.0)
        ->and(config('flux.connection_timeout_seconds'))->toBe(1.0)
        ->and(config('flux.dispatch_timeout_seconds'))->toBe(35.0);
});

function withFakeFluxSocket(string $response, Closure $callback): void
{
    $directory = storage_path('framework/testing');

    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    $socketPath = $directory.'/flux-'.bin2hex(random_bytes(8)).'.sock';
    $server = stream_socket_server("unix://{$socketPath}", $errorCode, $errorMessage);

    expect($server)->not->toBeFalse("Could not create fake Flux socket: {$errorMessage} ({$errorCode})");

    $pid = pcntl_fork();

    if ($pid === 0) {
        $connection = stream_socket_accept($server, 5);

        if ($connection !== false) {
            $request = '';

            while (! str_contains($request, "\r\n\r\n") && ! feof($connection)) {
                $request .= fread($connection, 8192);
            }

            fwrite($connection, $response);
            fclose($connection);
        }

        fclose($server);
        exit(0);
    }

    fclose($server);
    Config::set('flux.unix_socket_path', $socketPath);
    Config::set('flux.health_timeout_seconds', 1.0);
    Config::set('flux.connection_timeout_seconds', 1.0);
    Config::set('flux.dispatch_timeout_seconds', 1.0);

    try {
        $callback();
    } finally {
        pcntl_waitpid($pid, $status);
        @unlink($socketPath);
    }
}

it('dispatches container inventory through the containers list primitive', function () {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl is required to fake a Flux Unix socket.');
    }

    $body = json_encode([
        'request_id' => 'test-request',
        'status' => 'ok',
        'data' => [],
    ], JSON_THROW_ON_ERROR);
    $requestPath = storage_path('framework/testing/flux-request-'.bin2hex(random_bytes(8)).'.txt');

    withFakeFluxSocketCapturingRequest(
        "HTTP/1.1 200 OK\r\n".
        "Content-Type: application/json\r\n".
        'Content-Length: '.strlen($body)."\r\n".
        "\r\n".
        $body,
        $requestPath,
        fn () => (new FluxClient)->listContainers('100.64.0.10')
    );

    $request = file_get_contents($requestPath) ?: '';
    @unlink($requestPath);

    expect($request)->toContain('"type":"containers.list"')
        ->not->toContain('list_containers');
});

function withFakeFluxSocketCapturingRequest(string $response, string $requestPath, Closure $callback): void
{
    $directory = storage_path('framework/testing');

    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    $socketPath = $directory.'/flux-'.bin2hex(random_bytes(8)).'.sock';
    $server = stream_socket_server("unix://{$socketPath}", $errorCode, $errorMessage);

    expect($server)->not->toBeFalse("Could not create fake Flux socket: {$errorMessage} ({$errorCode})");

    $pid = pcntl_fork();

    if ($pid === 0) {
        $connection = stream_socket_accept($server, 5);

        if ($connection !== false) {
            $request = '';

            while (! str_contains($request, "\r\n\r\n") && ! feof($connection)) {
                $request .= fread($connection, 8192);
            }

            if (preg_match('/Content-Length: (\d+)/i', $request, $matches) === 1) {
                $remaining = (int) $matches[1] - strlen(substr($request, strpos($request, "\r\n\r\n") + 4));

                while ($remaining > 0 && ! feof($connection)) {
                    $chunk = fread($connection, $remaining);
                    $request .= $chunk;
                    $remaining -= strlen($chunk);
                }
            }

            file_put_contents($requestPath, $request);
            fwrite($connection, $response);
            fclose($connection);
        }

        fclose($server);
        exit(0);
    }

    fclose($server);

    try {
        config(['flux.unix_socket_path' => $socketPath]);
        $callback();
        pcntl_waitpid($pid, $status);
    } finally {
        @unlink($socketPath);
    }
}
