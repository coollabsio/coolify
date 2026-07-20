<?php

use App\Models\StandaloneDocker;

it('reports no bound ip when bind_ip is null', function () {
    $destination = new StandaloneDocker;
    $destination->id = 7;
    $destination->bind_ip = null;

    expect($destination->hasBoundIp())->toBeFalse();
    expect($destination->traefikEntrypointSuffix())->toBeNull();
});

it('builds a deterministic traefik entrypoint suffix and internal ports from the id', function () {
    $destination = new StandaloneDocker;
    $destination->id = 5;
    $destination->bind_ip = '192.168.1.10';

    expect($destination->hasBoundIp())->toBeTrue();
    expect($destination->traefikEntrypointSuffix())->toBe('dest5');
    expect($destination->traefikInternalHttpPort())->toBe(8010);
    expect($destination->traefikInternalHttpsPort())->toBe(8011);
});

it('allocates non-overlapping internal ports across destinations', function () {
    $a = new StandaloneDocker;
    $a->id = 1;
    $a->bind_ip = '10.0.0.1';

    $b = new StandaloneDocker;
    $b->id = 2;
    $b->bind_ip = '10.0.0.2';

    expect($a->traefikInternalHttpPort())->not->toBe($b->traefikInternalHttpPort());
    expect($a->traefikInternalHttpsPort())->not->toBe($b->traefikInternalHttpsPort());
    expect($a->traefikInternalHttpsPort())->not->toBe($b->traefikInternalHttpPort());
});
