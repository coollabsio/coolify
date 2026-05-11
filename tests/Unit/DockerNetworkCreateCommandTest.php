<?php

it('builds standalone Docker network create commands with IPv6 fallback', function () {
    $command = dockerNetworkCreateCommand('coolify', hideOutput: true);

    expect($command)->toBe("(docker network create --attachable --ipv6 'coolify' >/dev/null 2>&1 || docker network create --attachable 'coolify' >/dev/null 2>&1)");
});

it('can leave fallback Docker network create output visible', function () {
    $command = dockerNetworkCreateCommand('app-network');

    expect($command)->toBe("(docker network create --attachable --ipv6 'app-network' >/dev/null 2>&1 || docker network create --attachable 'app-network')");
});

it('keeps swarm Docker network create commands on overlay networks', function () {
    $command = dockerNetworkCreateCommand('coolify-overlay', isSwarm: true, hideOutput: true);

    expect($command)->toBe("docker network create --attachable --driver overlay 'coolify-overlay' >/dev/null 2>&1");
    expect($command)->not->toContain('--ipv6');
});

it('escapes Docker network names in create commands', function () {
    $command = dockerNetworkCreateCommand("team's-network", hideOutput: true);

    expect($command)->toContain("'team'\\''s-network'");
});
