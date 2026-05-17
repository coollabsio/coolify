<?php

test('docker network creation tries ipv6 before falling back to ipv4', function () {
    $command = dockerNetworkCreateCommand("'coolify'");

    expect($command)
        ->toContain('docker network create --attachable --ipv6')
        ->toContain("docker network create --attachable 'coolify'")
        ->toContain('||');
});

test('swarm network creation preserves overlay driver in ipv6 and fallback commands', function () {
    $command = dockerNetworkCreateCommand("'coolify-overlay'", true);

    expect($command)
        ->toContain("docker network create --driver overlay --attachable --ipv6 'coolify-overlay'")
        ->toContain("docker network create --driver overlay --attachable 'coolify-overlay'");
});

test('quiet network creation suppresses the ipv6 probe output', function () {
    $command = dockerNetworkCreateCommand("'coolify'", false, true);

    expect($command)
        ->toContain("docker network create --attachable --ipv6 'coolify' >/dev/null 2>&1")
        ->toContain("docker network create --attachable 'coolify' >/dev/null");
});
