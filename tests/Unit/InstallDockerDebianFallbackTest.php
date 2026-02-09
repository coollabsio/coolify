<?php

use App\Actions\Server\InstallDocker;

it('uses a dynamic Debian codename fallback for Docker repo setup', function () {
    $reflection = new ReflectionClass(InstallDocker::class);
    $source = file_get_contents($reflection->getFileName());

    expect($source)
        ->toContain('DOCKER_CODENAME=${VERSION_CODENAME}')
        ->toContain('https://download.docker.com/linux/${ID}/dists/${VERSION_CODENAME}/Release')
        ->toContain('DOCKER_CODENAME=bookworm')
        ->toContain('https://download.docker.com/linux/${ID} ${DOCKER_CODENAME} stable');
})->note('Debian Docker install falls back to bookworm when the repo codename is missing');
