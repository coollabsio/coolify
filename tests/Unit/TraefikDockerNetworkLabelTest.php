<?php

it('adds the Coolify network when the user did not select a Traefik network', function () {
    $labels = addTraefikDockerNetworkLabel(collect([
        'traefik.enable=true',
    ]), 'app-uuid');

    expect($labels->values()->all())->toContain('traefik.docker.network=app-uuid');
});

it('preserves a user-selected Traefik network', function () {
    $labels = addTraefikDockerNetworkLabel(collect([
        'traefik.enable=true',
        'traefik.docker.network=custom-network',
    ]), 'app-uuid');

    expect($labels->values()->all())
        ->toContain('traefik.docker.network=custom-network')
        ->not->toContain('traefik.docker.network=app-uuid');
});

it('treats a bare user-provided Traefik network label as authoritative', function () {
    $labels = addTraefikDockerNetworkLabel(collect([
        'traefik.docker.network',
    ]), 'app-uuid');

    expect($labels->values()->all())
        ->toContain('traefik.docker.network')
        ->not->toContain('traefik.docker.network=app-uuid');
});
