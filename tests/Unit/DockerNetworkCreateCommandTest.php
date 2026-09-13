<?php

it('generates a command that checks for the network, prefers IPv6 and falls back to IPv4-only', function () {
    $command = dockerNetworkCreateCommand('coolify');

    expect($command)->toBe(
        "docker network inspect 'coolify' >/dev/null 2>&1"
        ." || docker network create --attachable --ipv6 'coolify' >/dev/null 2>&1"
        ." || docker network create --attachable 'coolify' >/dev/null"
    );
});

it('skips creation when the network already exists', function () {
    $command = dockerNetworkCreateCommand('coolify');

    expect($command)->toStartWith("docker network inspect 'coolify'");
});

it('escapes the network name in every part of the command', function () {
    $command = dockerNetworkCreateCommand('net; rm -rf /');

    expect($command)
        ->toContain("inspect 'net; rm -rf /'")
        ->toContain("--ipv6 'net; rm -rf /'")
        ->toContain("--attachable 'net; rm -rf /' >/dev/null");
});
