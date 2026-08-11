<?php

it('publishes v4 branch builds under the commit sha with a traceable internal version', function () {
    $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/coolify-sha-build.yml');
    $dockerfile = file_get_contents(dirname(__DIR__, 2).'/docker/production/Dockerfile');
    $constants = file_get_contents(dirname(__DIR__, 2).'/config/constants.php');

    expect($workflow)
        ->toContain('name: Build Coolify (SHA)')
        ->toContain('branches: ["v4.x"]')
        ->not->toContain('branches: ["v4.x", "main"]')
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
        ->toContain('name: Release Coolify Stable')
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

it('keeps support image workflows ready for the production branch rename', function (string $workflowFile) {
    $workflow = file_get_contents(dirname(__DIR__, 2)."/.github/workflows/{$workflowFile}");

    expect($workflow)->toContain('branches: [ "v4.x", "main" ]');
})->with([
    'helper' => 'coolify-helper.yml',
    'realtime' => 'coolify-realtime.yml',
]);

it('generates the production changelog only from v4.x', function () {
    $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/generate-changelog.yml');

    expect($workflow)
        ->toContain('branches: [ v4.x ]')
        ->not->toContain('main');
});

it('excludes only active production branches from staging builds', function () {
    $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/coolify-staging-build.yml');

    expect($workflow)
        ->toContain('      - v4.x')
        ->not->toContain('      - main');
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

it('documents the production, rc, and hotfix release flows', function () {
    $releaseGuide = file_get_contents(dirname(__DIR__, 2).'/RELEASE.md');

    expect($releaseGuide)
        ->toContain('| `main` | Latest production source |')
        ->toContain('| `next` | Feature integration and RC releases |')
        ->toContain('| `hotfix/X.Y.Z` | Production fixes based on `main` |')
        ->toContain('feature/* → next → RC')
        ->toContain('next → main → stable release')
        ->toContain('main → hotfix/X.Y.Z → main → next')
        ->toContain('reviewed draft GitHub Release')
        ->toContain('workflows never edit or commit versions')
        ->toContain('Update the CDN only after the release is approved')
        ->not->toContain('`edge`')
        ->not->toContain('promotes the existing SHA image');
});
