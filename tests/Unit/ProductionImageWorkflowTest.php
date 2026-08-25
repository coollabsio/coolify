<?php

it('publishes v4 branch builds under the commit sha with a traceable internal version', function () {
    $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/coolify-sha-build.yml');
    $dockerfile = file_get_contents(dirname(__DIR__, 2).'/docker/production/Dockerfile');
    $constants = file_get_contents(dirname(__DIR__, 2).'/config/constants.php');
    $versions = json_decode(file_get_contents(dirname(__DIR__, 2).'/versions.json'), true, flags: JSON_THROW_ON_ERROR);
    $nightlyVersions = json_decode(file_get_contents(dirname(__DIR__, 2).'/other/nightly/versions.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($workflow)
        ->toContain('name: Build Coolify (SHA)')
        ->toContain('branches: ["main"]')
        ->not->toContain('v4.x')
        ->toContain('short_sha=${GITHUB_SHA::7}')
        ->toContain('sha-${{ steps.version.outputs.short_sha }}-${{ matrix.arch }}')
        ->toContain('SHA: ${{ needs.build-push.outputs.short_sha }}')
        ->not->toContain('sha-${{ github.sha }}')
        ->toContain('php bootstrap/getVersion.php')
        ->toContain('version=${BASE_VERSION}-dev.${GITHUB_SHA::9}')
        ->toContain('COOLIFY_VERSION=${{ steps.version.outputs.version }}')
        ->not->toContain('IMAGE_NAME }}:latest')
        ->and($dockerfile)
        ->toContain('ARG COOLIFY_VERSION')
        ->toContain('ENV COOLIFY_VERSION=${COOLIFY_VERSION}')
        ->and($constants)
        ->toContain("'version' => env('COOLIFY_VERSION') ?: '4.3.11'")
        ->and($versions['coolify']['v4']['version'])->toBe('4.3.11')
        ->and($versions['coolify']['nightly']['version'])->toBe('4.4-rc.1')
        ->and($nightlyVersions)->toBe($versions);
});

it('orders a maintenance development build before its stable release', function () {
    expect(version_compare('4.3.2-dev.d64cbda3e', '4.3.2', '<'))->toBeTrue()
        ->and(version_compare('4.3.2', '4.3.2-dev.d64cbda3e', '>'))->toBeTrue();
});

it('orders rolling and exact release candidates before the stable release', function () {
    expect(version_compare('4.4-rc.1.d64cbda', '4.4-rc.1', '<'))->toBeTrue()
        ->and(version_compare('4.4-rc.1', '4.4-rc.2', '<'))->toBeTrue()
        ->and(version_compare('4.4-rc.2', '4.4.0', '<'))->toBeTrue();
});

it('requires a reviewed draft release before building a stable version', function () {
    $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/coolify-release.yml');

    expect($workflow)
        ->toContain('name: Release Coolify Stable')
        ->toContain('run-name: ${{ inputs.tag }}')
        ->toContain('workflow_dispatch:')
        ->toContain('tag:')
        ->toContain("github.ref_name != 'main'")
        ->toContain('github.paginate(github.rest.repos.listReleases')
        ->toContain('release.draft')
        ->toContain('release.prerelease')
        ->toContain('release.body?.trim()')
        ->toContain('bootstrap/getVersion.php')
        ->toContain('target_commitish: context.sha')
        ->toContain('tag_name: process.env.TAG_NAME')
        ->toContain('actions/github-script@v8')
        ->not->toContain('actions/github-script@v7')
        ->not->toContain('environment: production-release')
        ->not->toContain('generate-notes');
});

it('runs support image workflows from main', function (string $workflowFile) {
    $workflow = file_get_contents(dirname(__DIR__, 2)."/.github/workflows/{$workflowFile}");

    expect($workflow)
        ->toContain('branches: [ "main" ]')
        ->not->toContain('v4.x');
})->with([
    'helper' => 'coolify-helper.yml',
    'realtime' => 'coolify-realtime.yml',
]);

it('prevents the stable helper workflow from publishing an existing version', function () {
    $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/coolify-helper.yml');

    expect($workflow)
        ->toContain('workflow_dispatch:')
        ->toContain('check-version:')
        ->toContain('needs: check-version')
        ->toContain('VERSION="${BASE_VERSION}"')
        ->toContain('docker buildx imagetools inspect "$IMAGE"')
        ->toContain('Version $VERSION already exists in $registry')
        ->toContain('Version $VERSION is available in both registries')
        ->toContain('Could not verify $IMAGE')
        ->toContain('cancel-in-progress: false');
});

it('prevents the stable realtime workflow from publishing an existing version', function () {
    $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/coolify-realtime.yml');

    expect($workflow)
        ->toContain('check-version:')
        ->toContain('needs: check-version')
        ->toContain('php bootstrap/getRealtimeVersion.php')
        ->toContain('VERSION="${BASE_VERSION}"')
        ->toContain('docker buildx imagetools inspect "$IMAGE"')
        ->toContain('Version $VERSION already exists in $registry')
        ->toContain('Version $VERSION is available in both registries')
        ->toContain('Could not verify $IMAGE')
        ->toContain('cancel-in-progress: false');
});

it('generates the production changelog from main', function () {
    $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/generate-changelog.yml');

    expect($workflow)
        ->toContain('branches: [ main ]')
        ->not->toContain('v4.x');
});

it('publishes traceable rolling builds from next without creating an exact rc tag', function () {
    $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/coolify-next-build.yml');

    expect($workflow)
        ->toContain('name: Build Coolify Next')
        ->toContain('branches: [next]')
        ->toContain('group: coolify-next-build')
        ->toContain("jq -r '.coolify.nightly.version' versions.json")
        ->toContain('VERSION="${RC_VERSION}.${SHORT_SHA}"')
        ->toContain('COOLIFY_VERSION=${{ needs.prepare.outputs.version }}')
        ->toContain('--tag "${IMAGE}:sha-${SHA}"')
        ->toContain('--tag "${IMAGE}:${VERSION}"')
        ->toContain('--tag "${IMAGE}:next"')
        ->not->toContain('--tag "${IMAGE}:${RC_VERSION}"')
        ->not->toContain('--tag "${IMAGE}:latest"');
});

it('requires a reviewed draft prerelease before publishing an exact rc', function () {
    $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/coolify-rc-release.yml');

    expect($workflow)
        ->toContain('name: Release Coolify RC')
        ->toContain('run-name: ${{ inputs.tag }}')
        ->toContain("github.ref != 'refs/heads/next'")
        ->toContain('group: coolify-rc-release')
        ->toContain('^v[0-9]+\\.[0-9]+-rc\\.[0-9]+$')
        ->toContain("jq -r '.coolify.nightly.version' versions.json")
        ->toContain('release.draft')
        ->toContain('!release.prerelease')
        ->toContain('release.body?.trim()')
        ->toContain('revalidate:')
        ->toMatch('/revalidate:.*?permissions:\s+contents: write/s')
        ->toContain('needs: [validate, build, revalidate]')
        ->toContain('COOLIFY_VERSION=${{ needs.validate.outputs.version }}')
        ->toContain('--tag "${IMAGE}:${VERSION}"')
        ->toContain('--tag "${IMAGE}:next"')
        ->toContain('prerelease: true')
        ->toContain('actions/github-script@v8')
        ->not->toContain('--tag "${IMAGE}:latest"')
        ->not->toContain('environment:');
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

it('documents pull request targets for fixes and features', function () {
    $contributingGuide = file_get_contents(dirname(__DIR__, 2).'/CONTRIBUTING.md');

    expect($contributingGuide)
        ->toContain('Fixes and small improvements')
        ->toContain('target `main`')
        ->toContain('New features and larger changes')
        ->toContain('target `next`')
        ->toContain('branch from `main`')
        ->toContain('branch from `next`')
        ->not->toContain('All pull requests must target the `next` branch');
});

it('guides issue authors to the correct contribution branch', function () {
    $bugReport = file_get_contents(dirname(__DIR__, 2).'/.github/ISSUE_TEMPLATE/01_BUG_REPORT.yml');
    $issueConfig = file_get_contents(dirname(__DIR__, 2).'/.github/ISSUE_TEMPLATE/config.yml');

    expect($bugReport)
        ->toContain('branch from `main` and target `main`')
        ->and($issueConfig)
        ->toContain('Feature code should branch from `next` and target `next`')
        ->toContain('Small fixes should target `main`; larger changes should target `next`');
});
