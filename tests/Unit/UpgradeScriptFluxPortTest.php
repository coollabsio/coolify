<?php

/**
 * Runs shell code after the Flux port helpers of the upgrade script, with a temporary .env file.
 *
 * @return array{output: string, env: string}
 */
function runUpgradeScriptFluxPortHelpers(string $initialEnv, string $shell): array
{
    $script = file_get_contents(dirname(__DIR__, 2).'/scripts/upgrade.sh');
    $functions = [];
    foreach (['update_env_var', 'host_port_in_use', 'coolify_publishes_port', 'ensure_flux_port'] as $name) {
        preg_match('/^'.$name.'\(\) \{.*?^\}$/ms', $script, $function);
        expect($function)->not->toBeEmpty();
        $functions[] = $function[0];
    }
    $envFile = tempnam(sys_get_temp_dir(), 'coolify-upgrade-env-');
    file_put_contents($envFile, $initialEnv);

    $process = proc_open(
        ['bash', '-c', implode("\n", ['log() { :; }', 'docker() { return 1; }', 'ENV_FILE='.escapeshellarg($envFile), ...$functions, $shell])],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    $env = file_get_contents($envFile);
    unlink($envFile);

    return ['output' => $output, 'env' => $env];
}

it('detects a program that listens on a host port', function (string $withoutSs) {
    $listener = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
    $check = $withoutSs."\nif host_port_in_use {$port}; then echo used; else echo free; fi";

    expect(runUpgradeScriptFluxPortHelpers('', $check)['output'])->toBe("used\n");

    fclose($listener);

    expect(runUpgradeScriptFluxPortHelpers('', $check)['output'])->toBe("free\n");
})->with([
    'with ss' => [''],
    'without ss' => ['command() { if [ "$2" = ss ]; then return 1; fi; builtin command "$@"; }'],
]);

it('writes the next free port as FLUX_PORT when another program uses port 7443', function (string $initialEnv) {
    $result = runUpgradeScriptFluxPortHelpers($initialEnv, implode("\n", [
        'host_port_in_use() { [ "$1" = 7443 ] || [ "$1" = 7444 ]; }',
        'ensure_flux_port',
    ]));

    expect($result['env'])->toContain("FLUX_PORT=7445\n")
        ->and(substr_count($result['env'], 'FLUX_PORT='))->toBe(1)
        ->and($result['env'])->toContain('APP_NAME=Coolify')
        ->and($result['output'])->toContain('NOTICE: Port 7443 is in use by another program. Coolify publishes Flux (Nodes) on port 7445 instead');
})->with([
    'no FLUX_PORT' => ["APP_NAME=Coolify\n"],
    'empty FLUX_PORT' => ["FLUX_PORT=\nAPP_NAME=Coolify\n"],
]);

it('keeps the Flux port', function (string $initialEnv, string $stubs) {
    $result = runUpgradeScriptFluxPortHelpers($initialEnv, $stubs."\nensure_flux_port");

    expect($result['env'])->toBe($initialEnv)
        ->and($result['output'])->toBe('');
})->with([
    'when port 7443 is free' => ["APP_NAME=Coolify\n", 'host_port_in_use() { return 1; }'],
    'when the Coolify container publishes port 7443' => [
        "APP_NAME=Coolify\n",
        "host_port_in_use() { return 0; }\ndocker() { printf '8080/tcp -> 0.0.0.0:8000\\n7443/tcp -> 0.0.0.0:7443\\n7443/tcp -> [::]:7443\\n'; }",
    ],
    'when FLUX_PORT is set' => ["FLUX_PORT=9443\nAPP_NAME=Coolify\n", 'host_port_in_use() { return 0; }'],
]);

it('does not take another published port of the Coolify container for port 7443', function () {
    $result = runUpgradeScriptFluxPortHelpers("APP_NAME=Coolify\n", implode("\n", [
        'host_port_in_use() { [ "$1" = 7443 ]; }',
        "docker() { printf '8080/tcp -> 0.0.0.0:17443\\n'; }",
        'ensure_flux_port',
    ]));

    expect($result['env'])->toContain("FLUX_PORT=7444\n");
});

it('checks the Flux port before the containers start', function () {
    $script = file_get_contents(dirname(__DIR__, 2).'/scripts/upgrade.sh');

    expect(strpos($script, "\nensure_flux_port\n"))->toBeInt()
        ->and(strpos($script, "\nensure_flux_port\n"))->toBeLessThan(strpos($script, 'up -d --remove-orphans'));
});
