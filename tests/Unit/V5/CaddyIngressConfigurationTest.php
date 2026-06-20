<?php

use App\Actions\V5\Proxy\GenerateCaddyIngressConfiguration;
use App\Actions\V5\Proxy\StartCaddyIngress;
use App\Actions\V5\Proxy\StopCaddyIngress;
use App\Models\PrivateKey;
use App\Models\V5\Server;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class);

it('generates a caddy ingress compose file with health endpoint', function () {
    $configuration = GenerateCaddyIngressConfiguration::run();

    expect($configuration['compose'])->toContain('container_name: coolify-v5-caddy')
        ->and($configuration['compose'])->toContain("image: 'docker.io/library/caddy:2-alpine'")
        ->and($configuration['compose'])->toContain('80:80')
        ->and($configuration['compose'])->toContain('443:443')
        ->and($configuration['compose'])->toContain('./Caddyfile:/etc/caddy/Caddyfile:ro')
        ->and($configuration['caddyfile'])->toContain('respond /coolify-health 200')
        ->and($configuration['caddyfile'])->toContain('respond 404');
});

it('builds caddy ingress install commands with sudo fallback for non-root ssh users', function () {
    $configuration = GenerateCaddyIngressConfiguration::run('/tmp/coolify-caddy');
    $script = implode("\n", $configuration['commands']);

    expect($configuration['commands'])->toHaveCount(6)
        ->and($script)->toContain('sudo mkdir -p /tmp/coolify-caddy/data /tmp/coolify-caddy/config')
        ->and($script)->toContain('sudo tee /tmp/coolify-caddy/docker-compose.yml')
        ->and($script)->toContain('sudo tee /tmp/coolify-caddy/Caddyfile')
        ->and($script)->toContain('command -v podman')
        ->and($script)->toContain('command -v docker')
        ->and(strpos($script, 'command -v podman'))->toBeLessThan(strpos($script, 'command -v docker'))
        ->and($script)->toContain('coolify-v5-caddy')
        ->and($script)->toContain('-v /tmp/coolify-caddy/Caddyfile:/etc/caddy/Caddyfile:ro');
});

it('throws when the caddy ingress start command fails', function () {
    $privateKey = new PrivateKey([
        'private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest-key\n-----END OPENSSH PRIVATE KEY-----\n",
    ]);

    $server = new Server([
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'capabilities' => ['coold', 'ingress'],
    ]);
    $server->setRelation('privateKey', $privateKey);

    Process::fake([
        '*' => Process::result(errorOutput: 'mkdir: Permission denied', exitCode: 1),
    ]);

    StartCaddyIngress::run($server);
})->throws(RuntimeException::class, 'Failed to start Caddy ingress: mkdir: Permission denied');

it('starts caddy ingress over ssh for ingress servers', function () {
    $privateKey = new PrivateKey([
        'private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest-key\n-----END OPENSSH PRIVATE KEY-----\n",
    ]);

    $server = new Server([
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'capabilities' => ['coold', 'ingress'],
    ]);
    $server->setRelation('privateKey', $privateKey);

    Process::fake([
        '*' => Process::result(output: ''),
    ]);

    $result = StartCaddyIngress::run($server);

    expect($result)->toBe('Caddy ingress started.');

    Process::assertRan(function ($process): bool {
        $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;

        return is_string($command)
            && str_contains($command, 'command -v podman')
            && str_contains($command, 'command -v docker')
            && strpos($command, 'command -v podman') < strpos($command, 'command -v docker')
            && str_contains($command, 'coolify-v5-caddy');
    });
});

it('prefers podman for every caddy ingress runtime command', function () {
    $configuration = GenerateCaddyIngressConfiguration::run('/tmp/coolify-caddy');

    $runtimeCommands = collect($configuration['commands'])
        ->filter(fn (string $command) => str_contains($command, 'command -v podman') && str_contains($command, 'command -v docker'));

    expect($runtimeCommands)->toHaveCount(3);

    $runtimeCommands->each(function (string $command): void {
        expect(strpos($command, 'command -v podman'))->toBeLessThan(strpos($command, 'command -v docker'));
    });
});

it('does not start caddy ingress for non-ingress servers', function () {
    $server = new Server([
        'capabilities' => ['coold'],
    ]);

    Process::fake();

    $result = StartCaddyIngress::run($server);

    expect($result)->toBe('Server is not an ingress server.');

    Process::assertNothingRan();
});

it('stops caddy ingress over ssh', function () {
    $privateKey = new PrivateKey([
        'private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest-key\n-----END OPENSSH PRIVATE KEY-----\n",
    ]);

    $server = new Server([
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'capabilities' => ['coold'],
    ]);
    $server->setRelation('privateKey', $privateKey);

    Process::fake([
        '*' => Process::result(output: ''),
    ]);

    $result = StopCaddyIngress::run($server);

    expect($result)->toBe('Caddy ingress stopped.');

    Process::assertRan(function ($process): bool {
        $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;

        return is_string($command)
            && str_contains($command, 'command -v podman')
            && str_contains($command, 'command -v docker')
            && strpos($command, 'command -v podman') < strpos($command, 'command -v docker')
            && str_contains($command, 'coolify-v5-caddy');
    });
});
