<?php

test('docker network create command prefers ipv6 and falls back to plain network creation', function () {
    $command = dockerNetworkCreateCommand('coolify');

    expect($command)
        ->toBe('(docker network create --attachable --ipv6 coolify >/dev/null 2>&1 || docker network create --attachable coolify >/dev/null)');
});

test('docker network create command can skip ipv6 for overlay networks', function () {
    $command = dockerNetworkCreateCommand('coolify-overlay', 'overlay', preferIpv6: false);

    expect($command)
        ->toBe('docker network create --driver overlay --attachable coolify-overlay >/dev/null');
});
