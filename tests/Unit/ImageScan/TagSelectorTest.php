<?php

use App\Services\ImageScan\ImageRef;
use App\Services\ImageScan\TagSelector;

it('selects the newest tag of the line and the newest higher major', function (string $current, array $tags, ?string $line, ?string $major, bool $floating) {
    expect((new TagSelector)->select($current, $tags))->toMatchArray(['line' => $line, 'major' => $major, 'floating' => $floating]);
})->with([
    'exact tag with suffix' => ['15.0-alpine', ['15.11-alpine', '15.15-alpine', '16.4-alpine', '15.16-rc1-alpine', '15-alpine'], '15.15-alpine', '16.4-alpine', false],
    'floating tag keeps the floating line tag' => ['15-alpine', ['15.11-alpine', '15.15-alpine', '16.4-alpine', '15.16-rc1-alpine', '15-alpine', '16-alpine'], '15-alpine', '16-alpine', true],
    'floating minor tag moves to the newest minor' => ['1.27', ['1.27', '1.27.3', '1.28', '1.28.0', '1.29', '1.29.1', '2.0'], '1.29', '2.0', true],
    'other suffixes are another line' => ['15.0', ['15.11-alpine', '15.12-bookworm', '15.10'], '15.10', null, false],
    'v prefix is kept' => ['v1.2.3', ['v1.2.4', 'v1.10.0', '1.11.0', 'v2.0.0', 'v1.9.9-beta.1'], 'v1.10.0', 'v2.0.0', false],
    'no newer tag' => ['15.15-alpine', ['15.11-alpine', '15.15-alpine'], null, null, false],
    'pre-release words are dropped' => ['2.1.0', ['2.2.0-rc1', '2.2.0-beta', '2.2.0-alpha.2', '2.2.0-dev', '2.2.0-nightly', '2.2.0-edge', '2.2.0-preview', '2.2.0-canary', '3.0.0-RC2'], null, null, false],
    'git sha tag' => ['sha-3f2a1c', ['sha-3f2a1c', 'sha-4a5b6c', '1.0.0'], null, null, false],
    'branch tag' => ['main', ['main', 'develop', '1.0.0'], null, null, false],
    'latest tag' => ['latest', ['latest', '1.0.0', '2.0.0'], null, null, false],
    'GHCR cosign tags are ignored' => ['2024.10.1', ['2024.10.1', '2024.10.4', 'sha256-abc.sig', 'sha256-0123456789abcdef.att', '2024.11.0.sbom', '2025.1.0'], '2024.10.4', '2025.1.0', false],
    'invalid tags are ignored' => ['1.0', ['1.1', '1.2 ', '-1.3', '1.4$(id)', '..1.5'], '1.1', null, false],
]);

it('selects the newest patch of the same minor before the newest tag of the line', function (string $current, array $tags, ?string $patch, ?string $line) {
    expect((new TagSelector)->select($current, $tags))->toMatchArray(['patch' => $patch, 'line' => $line]);
})->with([
    'patch and newer minor' => ['8.0.40', ['8.0.40', '8.0.41', '8.0.45', '8.4.13', '9.7.4'], '8.0.45', '8.4.13'],
    'v prefix and suffix are kept' => ['v1.2.3-alpine', ['v1.2.9-alpine', 'v1.2.10', 'v1.5.0-alpine'], 'v1.2.9-alpine', 'v1.5.0-alpine'],
    'the newest patch is the line tag' => ['1.27.1', ['1.27.1', '1.27.4'], null, '1.27.4'],
    'no newer patch' => ['8.0.45', ['8.0.45', '8.4.13'], null, '8.4.13'],
    'two version parts have no patch line' => ['15.4', ['15.4', '15.13'], null, '15.13'],
    'a floating tag has no patch line' => ['1.27', ['1.27', '1.27.3', '1.28', '1.28.0'], null, '1.28'],
]);

it('selects tags for an image of a registry with a port', function () {
    $ref = ImageRef::parse('localhost:5000/team/app:1.2');

    expect($ref->tag)->toBe('1.2')
        ->and($ref->qualifiedName())->toBe('localhost:5000/team/app')
        ->and((new TagSelector)->select($ref->tag, ['1.2', '1.3', '2.0']))->toBe(['line' => '1.3', 'patch' => null, 'major' => '2.0', 'floating' => false]);
});

it('reads at most 10,000 tags', function () {
    $tags = array_map(fn (int $patch) => "1.0.{$patch}", range(1, 10_000));
    $tags[] = '1.0.99999';

    expect(TagSelector::MAX_TAGS)->toBe(10_000)
        ->and((new TagSelector)->select('1.0.0', $tags)['line'])->toBe('1.0.10000');
});
