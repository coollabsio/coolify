<?php

use Symfony\Component\Process\Process;

test('sentinel startup regenerates an empty endpoint from instance settings', function () {
    $action = file_get_contents(dirname(__DIR__, 2).'/app/Actions/Server/StartSentinel.php');
    $component = file_get_contents(dirname(__DIR__, 2).'/app/Livewire/Server/Sentinel.php');

    expect($action)
        ->toContain('ensureSentinelUrl()')
        ->and($component)
        ->toContain('$this->sentinelCustomUrl = $this->server->settings->sentinel_custom_url;')
        ->and(file_get_contents(dirname(__DIR__, 2).'/app/Models/ServerSetting.php'))
        ->toContain('generateSentinelUrl(ignoreEvent: true)')
        ->toContain('sentinelUrlFromCurrentRequest()')
        ->toContain('Set an instance FQDN, public IP, or reachable Coolify URL before enabling Sentinel.')
        ->and($component)
        ->toContain('$this->sentinelCustomUrl = $this->server->settings->sentinel_custom_url;');
});

test('sentinel startup allows legacy metrics migrations to finish before health checks fail', function () {
    $action = file_get_contents(dirname(__DIR__, 2).'/app/Actions/Server/StartSentinel.php');

    expect($action)->toContain('--health-start-period 120s');
});

test('local sentinel joins the Coolify network and waits for an authenticated push before becoming live', function () {
    $action = file_get_contents(dirname(__DIR__, 2).'/app/Actions/Server/StartSentinel.php');

    expect($action)
        ->toContain('--network coolify')
        ->toContain('sentinelHeartbeat(isReset: true)')
        ->toContain("sentinel_waiting_since' => now()")
        ->not->toContain('$server->sentinelHeartbeat();');
});

test('sentinel removes its temporary token on startup success and failure', function () {
    $source = file_get_contents(dirname(__DIR__, 2).'/app/Actions/Server/StartSentinel.php');
    preg_match("/'sh -ec '\\.escapeshellarg\\(implode\\(\\\"\\\\n\\\", \\[(.*?)\\]\\)\\)/s", $source, $match);
    expect($match)->toHaveCount(2);
    foreach (['true', 'false'] as $dockerCommand) {
        $envFile = tempnam(sys_get_temp_dir(), 'sentinel-token-');
        $envFileContent = 'TOKEN=test';
        $commands = eval('return ['.$match[1].'];');
        $process = new Process(['sh', '-ec', implode("\n", $commands)]);
        $process->run();
        expect($process->getExitCode())->toBe($dockerCommand === 'true' ? 0 : 1);
        expect(file_exists($envFile))->toBeFalse();
    }
});
