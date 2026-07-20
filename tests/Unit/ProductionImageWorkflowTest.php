<?php

it('publishes v4 branch builds only under the commit sha', function () {
    $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/coolify-sha-build.yml');

    expect($workflow)
        ->toContain('name: Build Coolify (SHA)')
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

it('documents the sha image release process', function () {
    $releaseGuide = file_get_contents(dirname(__DIR__, 2).'/RELEASE.md');

    expect($releaseGuide)
        ->toContain('## Branch Strategy')
        ->toContain('Fixes and release-ready patches')
        ->toContain('open PRs against **`v4.x`**')
        ->toContain('open PRs against **`next`**')
        ->toContain('merge `v4.x` back into `next`')
        ->toContain('Merge the release commit into `v4.x`')
        ->toContain('`Build Coolify (SHA)`')
        ->toContain('`sha-<commit-sha>`')
        ->toContain('targeting the exact commit that produced the SHA image')
        ->toContain('promotes the existing SHA image without rebuilding it')
        ->toContain('Update the CDN')
        ->toContain('Only commits on **`v4.x`** produce production SHA images')
        ->not->toContain('Merging to `main`')
        ->not->toContain('Production Build (v4)');
});
