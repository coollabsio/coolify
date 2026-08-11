<?php

it('publishes v4 branch builds under the commit sha with a traceable internal version', function () {
    $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/coolify-sha-build.yml');
    $dockerfile = file_get_contents(dirname(__DIR__, 2).'/docker/production/Dockerfile');
    $constants = file_get_contents(dirname(__DIR__, 2).'/config/constants.php');

    expect($workflow)
        ->toContain('name: Build Coolify (SHA)')
        ->toContain('sha-${{ github.sha }}-${{ matrix.arch }}')
        ->toContain('sha-${{ github.sha }}')
        ->toContain('php bootstrap/getVersion.php')
        ->toContain('version=${BASE_VERSION}-dev.${GITHUB_SHA::9}')
        ->toContain('COOLIFY_VERSION=${{ steps.version.outputs.version }}')
        ->not->toContain('IMAGE_NAME }}:latest')
        ->and($dockerfile)
        ->toContain('ARG COOLIFY_VERSION')
        ->toContain('ENV COOLIFY_VERSION=${COOLIFY_VERSION}')
        ->and($constants)
        ->toContain("'version' => env('COOLIFY_VERSION') ?: '4.3.0'");
});

it('orders a maintenance development build before its stable release', function () {
    expect(version_compare('4.3.0-dev.d64cbda3e', '4.3.0', '<'))->toBeTrue()
        ->and(version_compare('4.3.0', '4.3.0-dev.d64cbda3e', '>'))->toBeTrue();
});

it('requires a reviewed draft release before building a stable version', function () {
    $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/coolify-release.yml');

    expect($workflow)
        ->toContain('workflow_dispatch:')
        ->toContain('tag:')
        ->toContain('github.ref_name != \'v4.x\'')
        ->toContain('github.paginate(github.rest.repos.listReleases')
        ->toContain('release.draft')
        ->toContain('release.prerelease')
        ->toContain('release.body?.trim()')
        ->toContain('bootstrap/getVersion.php')
        ->toContain('target_commitish: context.sha')
        ->not->toContain('generate-notes');
});

it('rebuilds stable images and publishes the reviewed draft after both architectures succeed', function () {
    $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/coolify-release.yml');

    expect($workflow)
        ->toContain('docker/build-push-action@v6')
        ->toContain('COOLIFY_VERSION=${{ needs.validate.outputs.version }}')
        ->toContain('release-${{ needs.validate.outputs.version }}-${{ github.sha }}-${{ matrix.arch }}')
        ->toContain('--tag "${IMAGE}:${VERSION}"')
        ->toContain('--tag "${IMAGE}:latest"')
        ->toContain('github.rest.repos.getRelease')
        ->toContain('release.target_commitish !== context.sha')
        ->toContain('release_id: Number(process.env.RELEASE_ID)')
        ->toContain('draft: false')
        ->toContain('permissions: {}')
        ->not->toContain('sarisia/actions-status-discord@v1')
        ->not->toContain('SOURCE_TAG="sha-${RELEASE_SHA}"');
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
        ->toContain('Create a reviewed draft GitHub release')
        ->toContain('rebuilds AMD64 and ARM64 images with the exact stable version')
        ->toContain('publishes the existing draft release')
        ->toContain('Update the CDN')
        ->toContain('Only commits on **`v4.x`** produce production SHA images')
        ->not->toContain('`edge`')
        ->not->toContain('promotes the existing SHA image')
        ->not->toContain('Merging to `main`')
        ->not->toContain('Production Build (v4)');
});
