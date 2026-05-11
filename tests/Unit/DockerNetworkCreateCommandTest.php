<?php

it('creates bridge networks with IPv6 and falls back to IPv4-only networks', function () {
    $command = dockerNetworkCreateCommand('coolify');

    expect($command)
        ->toContain('docker network inspect coolify >/dev/null 2>&1')
        ->toContain('docker network create --attachable --ipv6 coolify >/dev/null 2>&1')
        ->toContain('docker network create --attachable coolify >/dev/null 2>&1 || true');
});

it('creates overlay networks with IPv6 and falls back to IPv4-only networks', function () {
    $command = dockerNetworkCreateCommand("'coolify-overlay'", 'overlay');

    expect($command)
        ->toContain("docker network inspect 'coolify-overlay' >/dev/null 2>&1")
        ->toContain("docker network create --driver overlay --attachable --ipv6 'coolify-overlay' >/dev/null 2>&1")
        ->toContain("docker network create --driver overlay --attachable 'coolify-overlay' >/dev/null 2>&1 || true");
});
