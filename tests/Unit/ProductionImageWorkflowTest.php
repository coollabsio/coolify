<?php

it('orders nightlies after the version they build on and before the next prerelease', function () {
    expect(version_compare('4.5.0', '4.6.0-alpha.0.20261014.1', '<'))->toBeTrue()
        ->and(version_compare('4.6.0-alpha.0.20261014.1', '4.6.0-alpha.0.20261014.2', '<'))->toBeTrue()
        ->and(version_compare('4.6.0-alpha.0.20261014.2', '4.6.0-alpha.1', '<'))->toBeTrue()
        ->and(version_compare('4.6.0-alpha.1', '4.6.0-alpha.1.20261014.1', '<'))->toBeTrue()
        ->and(version_compare('4.6.0-beta.1.20261014.2', '4.6.0-beta.2', '<'))->toBeTrue()
        ->and(version_compare('4.6.0-rc.1.20261015.1', '4.6.0', '<'))->toBeTrue()
        ->and(version_compare('4.6.0', '4.7.0-alpha.0.20261016.1', '<'))->toBeTrue();
});

it('orders the image of a pull request after the version of its base branch and before the next version', function () {
    expect(version_compare('4.5.0', '4.5.0-pr.12', '<'))->toBeTrue()
        ->and(version_compare('4.5.0-pr.12', '4.5.1', '<'))->toBeTrue()
        ->and(version_compare('4.6.0-beta.1.20261014.3', '4.6.0-beta.1-pr.12', '<'))->toBeTrue()
        ->and(version_compare('4.6.0-beta.1-pr.12', '4.6.0-beta.2', '<'))->toBeTrue();
});

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

it('builds the development helper image from the coolify-helper repository', function () {
    expect(file_get_contents(dirname(__DIR__, 2).'/scripts/dev-helper'))->toContain('https://github.com/coollabsio/coolify-helper.git#main:src')
        ->and(file_get_contents(dirname(__DIR__, 2).'/app/Livewire/Settings/Index.php'))->toContain('https://github.com/coollabsio/coolify-helper.git#main:src');
});

it('builds the testing host only for local development', function () {
    $developmentCompose = file_get_contents(dirname(__DIR__, 2).'/docker-compose.dev.yml');

    expect($developmentCompose)->toContain('image: coolify-testing-host:dev')
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
