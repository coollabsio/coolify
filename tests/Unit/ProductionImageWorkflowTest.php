<?php

it('reads the Coolify and helper versions of its channel from versions.json in install.sh', function (string $channel, string $version) {
    $root = dirname(__DIR__, 2);
    $upstream = json_decode(file_get_contents("{$root}/upstream.json"), true);
    $versions = json_encode([
        'stable' => ['coolify' => ['version' => '4.5.1']] + $upstream,
        'preview' => ['coolify' => ['version' => '4.6.0-beta.1']] + $upstream,
        'nightly' => ['coolify' => ['version' => '4.6.0-beta.1.20261027.1']] + $upstream,
        'coolify' => ['v4' => ['version' => '4.5.1']],
    ], JSON_PRETTY_PRINT);
    $parse = implode("\n", preg_grep('/^(CHANNEL_VERSIONS|LATEST_(HELPER_)?VERSION)=\$\(echo "\$(VERSIONS_JSON|CHANNEL_VERSIONS)"/', file("{$root}/scripts/install.sh")));
    $output = shell_exec('CHANNEL='.escapeshellarg($channel).' VERSIONS_JSON='.escapeshellarg($versions).' bash -c '.escapeshellarg($parse."\necho \"\$LATEST_VERSION \$LATEST_HELPER_VERSION\""));

    expect(trim($output))->toBe("{$version} {$upstream['helper']['version']}");
})->with([
    'stable' => ['stable', '4.5.1'],
    'preview' => ['preview', '4.6.0-beta.1'],
    'nightly' => ['nightly', '4.6.0-beta.1.20261027.1'],
]);

it('selects the channel from the --preview or --nightly flag in install.sh', function (array $arguments, string $selection) {
    preg_match('/^CDN=.*?^esac$/ms', file_get_contents(dirname(__DIR__, 2).'/scripts/install.sh'), $channelSelection);
    $output = shell_exec('bash -c '.escapeshellarg($channelSelection[0]."\necho \"\$CHANNEL \$CDN \$1\"").' install.sh '.implode(' ', array_map('escapeshellarg', $arguments)));

    expect(trim($output))->toBe($selection);
})->with([
    'stable' => [[], 'stable https://cdn.coollabs.io/coolify'],
    'preview' => [['--preview'], 'preview https://cdn.coollabs.io/coolify/preview'],
    'nightly with a version' => [['--nightly', '4.6.0-beta.1.20261027.1'], 'nightly https://cdn.coollabs.io/coolify/nightly 4.6.0-beta.1.20261027.1'],
]);

it('builds the development helper image from the coolify-helper repository', function (string $file) {
    expect(file_get_contents(dirname(__DIR__, 2)."/{$file}"))->toContain('https://github.com/coollabsio/coolify-helper.git#main:src');
})->with(['scripts/dev-helper', 'docker-compose.dev.yml', 'docker-compose.dev-multi.yml']);

it('builds the testing host only for local development', function () {
    $developmentCompose = file_get_contents(dirname(__DIR__, 2).'/docker-compose.dev.yml');

    expect($developmentCompose)->toContain('image: coolify-testing-host:dev')
        ->toContain('dockerfile: ./docker/testing-host/Dockerfile');
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

it('ships release.json and upstream.json in the production image so version checks work right after an upgrade', function () {
    $dockerfile = file_get_contents(dirname(__DIR__, 2).'/docker/production/Dockerfile');
    $dockerignore = file_get_contents(dirname(__DIR__, 2).'/.dockerignore');

    expect($dockerfile)
        ->toContain('COPY --chown=www-data:www-data release.json upstream.json ./')
        ->not->toContain('versions.json')
        ->and($dockerignore)
        ->not->toMatch('/^\/?(release|upstream)\.json$/m');
});
