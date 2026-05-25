<?php

it('prefers ipv6 with fallback for coolify network', function () {
    $command = buildDockerNetworkCreateIfMissingCommand('coolify', preferIpv6: true);

    expect($command)
        ->toContain("docker network inspect 'coolify' >/dev/null 2>&1 ||")
        ->toContain("docker network create --attachable --ipv6 'coolify'")
        ->toContain("docker network create --attachable 'coolify'");
});

it('uses standard create for non-coolify networks', function () {
    $command = buildDockerNetworkCreateIfMissingCommand('app-network');

    expect($command)
        ->toContain("docker network inspect 'app-network' >/dev/null 2>&1 || docker network create --attachable 'app-network'")
        ->not->toContain('--ipv6');
});

it('keeps overlay creation path unchanged', function () {
    $command = buildDockerNetworkCreateIfMissingCommand('coolify-overlay', preferIpv6: true, overlay: true);

    expect($command)
        ->toContain("docker network create --driver overlay --attachable 'coolify-overlay'")
        ->not->toContain('--ipv6');
});
