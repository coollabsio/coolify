<?php

it('generates a command that checks for the network, prefers IPv6 and falls back to IPv4-only', function () {
    $safe = escapeshellarg('coolify');
    $command = dockerNetworkCreateCommand('coolify');

    expect($command)->toBe(
        "docker network inspect {$safe} >/dev/null 2>&1"
        ." || docker network create --attachable --ipv6 {$safe} >/dev/null 2>&1"
        ." || docker network create --attachable {$safe} >/dev/null"
    );
});

it('skips creation when the network already exists', function () {
    $safe = escapeshellarg('coolify');
    $command = dockerNetworkCreateCommand('coolify');

    expect($command)->toStartWith("docker network inspect {$safe}");
});

it('escapes the network name in every part of the command', function () {
    $network = 'net; rm -rf /';
    $safe = escapeshellarg($network);
    $command = dockerNetworkCreateCommand($network);

    expect($command)
        ->toContain("inspect {$safe}")
        ->toContain("--ipv6 {$safe}")
        ->toContain("--attachable {$safe} >/dev/null");
});
