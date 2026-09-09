<?php

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
