<?php

it('publishes v4 branch builds only under the commit sha', function () {
    $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/coolify-production-build.yml');

    expect($workflow)
        ->toContain('sha-${{ github.sha }}-${{ matrix.arch }}')
        ->toContain('sha-${{ github.sha }}')
        ->not->toContain('bootstrap/getVersion.php')
        ->not->toContain('steps.version.outputs.VERSION')
        ->not->toContain('IMAGE_NAME }}:latest');
});

it('promotes the released commit image without rebuilding it', function () {
    $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/coolify-release.yml');

    expect($workflow)
        ->toContain('release:')
        ->toContain('types: [published]')
        ->toContain('TAG_NAME: ${{ github.event.release.tag_name }}')
        ->toContain('git rev-list -n 1 "${TAG_NAME}"')
        ->toContain('SOURCE_TAG="sha-${RELEASE_SHA}"')
        ->toContain('bootstrap/getVersion.php')
        ->toContain('--tag "${IMAGE}:${VERSION}"')
        ->not->toContain('docker/build-push-action');
});

it('only promotes stable releases to latest', function () {
    $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/coolify-release.yml');

    expect($workflow)
        ->toContain('if: ${{ ! github.event.release.prerelease }}')
        ->toContain('--tag "${IMAGE}:latest"');
});
