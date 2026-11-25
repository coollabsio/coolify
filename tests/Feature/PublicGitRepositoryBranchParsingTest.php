<?php

use Spatie\Url\Url;

/**
 * Tests for branch name parsing from Git repository URLs.
 * Verifies that branch names containing slashes (e.g., fix/something)
 * are correctly extracted from URLs like /tree/fix/something.
 */
function parseBranchFromUrl(string $url): array
{
    $parsed = Url::fromString($url);
    $branch = 'main';
    $baseDirectory = '/';

    if ($parsed->getSegment(3) === 'tree') {
        $path = str($parsed->getPath())->trim('/');
        $branch = str($path)->after('tree/')->value();
        $baseDirectory = '/';
    }

    return [
        'branch' => $branch,
        'base_directory' => $baseDirectory,
        'repository' => $parsed->getSegment(1).'/'.$parsed->getSegment(2),
    ];
}

test('parses simple branch from GitHub URL', function () {
    $result = parseBranchFromUrl('https://github.com/andrasbacsai/coolify-examples/tree/main');

    expect($result['branch'])->toBe('main');
    expect($result['base_directory'])->toBe('/');
    expect($result['repository'])->toBe('andrasbacsai/coolify-examples');
});

test('parses branch with slash from GitHub URL', function () {
    $result = parseBranchFromUrl('https://github.com/andrasbacsai/coolify-examples-1/tree/fix/8854-env-var-fallback-volume');

    expect($result['branch'])->toBe('fix/8854-env-var-fallback-volume');
    expect($result['base_directory'])->toBe('/');
    expect($result['repository'])->toBe('andrasbacsai/coolify-examples-1');
});

test('parses branch with multiple slashes from GitHub URL', function () {
    $result = parseBranchFromUrl('https://github.com/user/repo/tree/feature/team/new-widget');

    expect($result['branch'])->toBe('feature/team/new-widget');
    expect($result['base_directory'])->toBe('/');
});

test('defaults to main branch when no tree segment in URL', function () {
    $result = parseBranchFromUrl('https://github.com/andrasbacsai/coolify-examples');

    expect($result['branch'])->toBe('main');
    expect($result['base_directory'])->toBe('/');
});

test('parses version-style branch with slash from GitHub URL', function () {
    $result = parseBranchFromUrl('https://github.com/coollabsio/coolify-examples/tree/release/v2.0');

    expect($result['branch'])->toBe('release/v2.0');
    expect($result['base_directory'])->toBe('/');
});

test('parses branch from non-GitHub URL with tree segment', function () {
    $result = parseBranchFromUrl('https://gitlab.com/user/repo/tree/hotfix/critical-bug');

    expect($result['branch'])->toBe('hotfix/critical-bug');
    expect($result['base_directory'])->toBe('/');
});
