<?php

it('reads the Coolify and helper versions by key from the versions.json scripts/sync-cdn.sh writes', function () {
    $versions = shell_exec("jq --indent 4 --arg version 4.6.0-beta.1 '{coolify: ({v4: {\$version}} + del(.traefik)), traefik}' ".escapeshellarg(dirname(__DIR__, 2).'/upstream.json'));
    $parse = implode("\n", preg_grep('/^LATEST_(HELPER_)?VERSION=\$\(echo "\$VERSIONS_JSON"/', file(dirname(__DIR__, 2).'/scripts/install.sh')));
    $output = shell_exec('VERSIONS_JSON='.escapeshellarg($versions).' bash -c '.escapeshellarg($parse."\necho \"\$LATEST_VERSION \$LATEST_HELPER_VERSION\""));
    $helper = json_decode(file_get_contents(dirname(__DIR__, 2).'/upstream.json'), true)['helper']['version'];

    expect(trim($output))->toBe("4.6.0-beta.1 {$helper}");
});

it('points the scripts at the stable channel of the CDN, which scripts/sync-cdn.sh rewrites for the other channels', function (string $script) {
    expect(file_get_contents(dirname(__DIR__, 2)."/{$script}"))->toMatch('/^CDN="https:\/\/cdn\.coollabs\.io\/coolify"$/m');
})->with(['scripts/install.sh', 'scripts/upgrade.sh']);

it('runs support image workflows from main', function (string $workflowFile) {
    $workflow = file_get_contents(dirname(__DIR__, 2)."/.github/workflows/{$workflowFile}");

    expect($workflow)
        ->toContain('branches: [ "main" ]')
        ->not->toContain('v4.x');
})->with([
    'helper' => 'coolify-helper.yml',
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

it('publishes the testing host only to Docker Hub', function () {
    $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/coolify-testing-host.yml');
    $cleanupWorkflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/cleanup-ghcr-untagged.yml');
    $windowsCompose = file_get_contents(dirname(__DIR__, 2).'/docker-compose.windows.yml');
    $developmentCompose = file_get_contents(dirname(__DIR__, 2).'/docker-compose.dev.yml');

    expect($workflow)
        ->toContain('DOCKER_REGISTRY: docker.io')
        ->toContain('IMAGE_NAME: "coollabsio/coolify-testing-host"')
        ->toContain('docker/testing-host/Dockerfile')
        ->not->toContain('ghcr.io')
        ->not->toContain('GITHUB_REGISTRY')
        ->and($cleanupWorkflow)->not->toContain('coolify-testing-host')
        ->and($windowsCompose)->toContain('docker.io/coollabsio/coolify-testing-host:latest')
        ->and($developmentCompose)->toContain('image: coolify-testing-host:dev')
        ->toContain('dockerfile: ./docker/testing-host/Dockerfile');
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

it('writes versions.json in the production image from upstream.json so version checks work right after an upgrade', function () {
    $dockerfile = file_get_contents(dirname(__DIR__, 2).'/docker/production/Dockerfile');
    $dockerignore = file_get_contents(dirname(__DIR__, 2).'/.dockerignore');

    expect($dockerfile)
        ->toContain('COPY --chown=www-data:www-data release.json upstream.json ./')
        ->toContain('upstream.json > versions.json')
        ->and($dockerignore)
        ->not->toMatch('/^\/?(release|upstream)\.json$/m');
});
