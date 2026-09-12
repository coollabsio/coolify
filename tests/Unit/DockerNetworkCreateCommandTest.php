<?php

it('creates proxy networks with IPv6 enabled and falls back to IPv4 only', function () {
    $command = dockerNetworkCreateCommand('coolify');

    expect($command)
        ->toBe("docker network create --attachable --ipv6 'coolify' >/dev/null 2>&1 || docker network create --attachable 'coolify' >/dev/null");
});

it('escapes the network name in both create attempts', function () {
    $command = dockerNetworkCreateCommand('net;rm -rf /');

    expect($command)
        ->toContain("--ipv6 'net;rm -rf /'")
        ->toContain("--attachable 'net;rm -rf /' >/dev/null")
        ->not->toContain('--attachable net;');
});
