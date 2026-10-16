<?php

/**
 * Runs the "Configuring Docker daemon" step of the install script against a temporary /etc/docker.
 * docker, restart_docker_service, and the terminal UI helpers are stubbed.
 *
 * @param  array<string, string>  $variables
 * @return array{config: ?array<string, mixed>, restarted: bool, output: string}
 */
function runInstallScriptDockerDaemonStep(string $scriptPath, ?string $daemonJson, int $runningContainers, array $variables = []): array
{
    $script = file_get_contents(dirname(__DIR__, 2).'/'.$scriptPath);
    preg_match('/^step_start "Configuring Docker daemon".*?^step_done$/ms', $script, $matches);
    expect($matches)->not->toBeEmpty();

    $etcDocker = sys_get_temp_dir().'/coolify-install-docker-'.bin2hex(random_bytes(6));
    mkdir($etcDocker);
    if ($daemonJson !== null) {
        file_put_contents("{$etcDocker}/daemon.json", $daemonJson);
    }

    $variables = [
        'DATE' => '20260101-000000',
        'DOCKER_ADDRESS_POOL_BASE' => '10.0.0.0/8',
        'DOCKER_ADDRESS_POOL_SIZE' => '24',
        'DOCKER_POOL_FORCE_OVERRIDE' => 'false',
        'EXISTING_POOL_CONFIGURED' => 'false',
        ...$variables,
    ];
    $prelude = implode("\n", array_map(fn ($name, $value) => "{$name}=".escapeshellarg($value), array_keys($variables), $variables));
    $stubs = <<<BASH
    set -e
    set -o pipefail
    step_start() { :; }
    step_done() { :; }
    warn() { echo "WARNING: \$*"; }
    restart_docker_service() { echo RESTARTED; }
    docker() { for i in \$(seq 1 {$runningContainers}); do echo "container\$i"; done; }
    BASH;

    $process = proc_open(
        ['bash', '-c', $stubs."\n".$prelude."\n".str_replace('/etc/docker', $etcDocker, $matches[0])],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    $config = file_exists("{$etcDocker}/daemon.json") ? json_decode(file_get_contents("{$etcDocker}/daemon.json"), true) : null;
    exec('rm -rf '.escapeshellarg($etcDocker));

    expect($exitCode)->toBe(0, $output);

    return ['config' => $config, 'restarted' => str_contains($output, 'RESTARTED'), 'output' => $output];
}

$installScripts = ['stable install' => 'scripts/install.sh', 'nightly install' => 'other/nightly/install.sh'];

it('does not create daemon.json or restart Docker when containers are running', function (string $scriptPath) {
    $result = runInstallScriptDockerDaemonStep($scriptPath, null, runningContainers: 30);

    expect($result['config'])->toBeNull()
        ->and($result['restarted'])->toBeFalse()
        ->and($result['output'])->toContain('stops 30 running container(s)')
        ->toContain('DOCKER_POOL_FORCE_OVERRIDE=true');
})->with($installScripts);

it('configures the network pool and restarts Docker when no containers are running', function (string $scriptPath) {
    $result = runInstallScriptDockerDaemonStep($scriptPath, null, runningContainers: 0);

    expect($result['config'])->toEqual([
        'default-address-pools' => [['base' => '10.0.0.0/8', 'size' => 24]],
        'log-driver' => 'json-file',
        'log-opts' => ['max-file' => '3', 'max-size' => '10m'],
    ])->and($result['restarted'])->toBeTrue();
})->with($installScripts);

it('keeps other daemon.json settings when it adds the network pool', function (string $scriptPath) {
    $result = runInstallScriptDockerDaemonStep($scriptPath, '{"registry-mirrors":["https://mirror.example.com"],"log-opts":{"compress":"true"}}', runningContainers: 0);

    expect($result['config'])->toMatchArray([
        'registry-mirrors' => ['https://mirror.example.com'],
        'default-address-pools' => [['base' => '10.0.0.0/8', 'size' => 24]],
        'log-opts' => ['compress' => 'true', 'max-file' => '3', 'max-size' => '10m'],
    ])->and($result['restarted'])->toBeTrue();
})->with($installScripts);

it('applies the network pool with running containers when DOCKER_POOL_FORCE_OVERRIDE is true', function (string $scriptPath) {
    $result = runInstallScriptDockerDaemonStep($scriptPath, '{"default-address-pools":[{"base":"172.16.0.0/12","size":20}]}', runningContainers: 5, variables: [
        'DOCKER_POOL_FORCE_OVERRIDE' => 'true',
        'EXISTING_POOL_CONFIGURED' => 'true',
    ]);

    expect($result['config']['default-address-pools'])->toBe([['base' => '10.0.0.0/8', 'size' => 24]])
        ->and($result['restarted'])->toBeTrue();
})->with($installScripts);

it('does not restart Docker when the configuration is already up to date', function (string $scriptPath) {
    $daemonJson = '{"log-driver":"json-file","log-opts":{"max-size":"10m","max-file":"3"},"default-address-pools":[{"base":"10.0.0.0/8","size":24}]}';
    $result = runInstallScriptDockerDaemonStep($scriptPath, $daemonJson, runningContainers: 0, variables: ['DOCKER_POOL_FORCE_OVERRIDE' => 'true']);

    expect($result['restarted'])->toBeFalse()
        ->and($result['output'])->toContain('Configuration is up to date');
})->with($installScripts);

it('does not change an existing network pool without DOCKER_POOL_FORCE_OVERRIDE', function (string $scriptPath) {
    $daemonJson = '{"default-address-pools":[{"base":"172.16.0.0/12","size":20}]}';
    $result = runInstallScriptDockerDaemonStep($scriptPath, $daemonJson, runningContainers: 0, variables: [
        'DOCKER_ADDRESS_POOL_BASE' => '172.16.0.0/12',
        'DOCKER_ADDRESS_POOL_SIZE' => '20',
        'EXISTING_POOL_CONFIGURED' => 'true',
    ]);

    expect($result['config'])->toBe(json_decode($daemonJson, true))
        ->and($result['restarted'])->toBeFalse();
})->with($installScripts);
