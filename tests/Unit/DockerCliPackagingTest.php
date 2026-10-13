<?php

it('installs the current Docker CLI in the Coolify helper image', function () {
    $dockerfile = file_get_contents(dirname(__DIR__, 2).'/docker/coolify-helper/Dockerfile');
    expect($dockerfile)
        ->toContain('ARG DOCKER_VERSION=29.7.2')
        ->toContain('ARG DOCKER_COMPOSE_VERSION=5.5.0')
        ->toContain('ARG DOCKER_BUILDX_VERSION=0.36.1')
        ->toContain('https://download.docker.com/linux/static/stable/x86_64/docker-${DOCKER_VERSION}.tgz')
        ->toContain('https://download.docker.com/linux/static/stable/aarch64/docker-${DOCKER_VERSION}.tgz')
        ->toMatch('/chmod \+x [^\n]*\/usr\/bin\/docker/');
});
