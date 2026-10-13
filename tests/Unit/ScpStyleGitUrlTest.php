<?php

it('parses scp-style ssh git urls including custom usernames and ports', function (string $url, array $expected) {
    expect(parseScpStyleGitUrl($url))->toBe($expected);
})->with([
    'git username' => [
        'git@github.com:organization/repository.git',
        [
            'user' => 'git',
            'host' => 'github.com',
            'port' => null,
            'path' => 'organization/repository.git',
        ],
    ],
    'custom username' => [
        'custom-user@git.example.com:organization/repository.git',
        [
            'user' => 'custom-user',
            'host' => 'git.example.com',
            'port' => null,
            'path' => 'organization/repository.git',
        ],
    ],
    'custom username and port' => [
        'custom-user@git.example.com:2222/organization/repository.git',
        [
            'user' => 'custom-user',
            'host' => 'git.example.com',
            'port' => '2222',
            'path' => 'organization/repository.git',
        ],
    ],
]);

it('converts scp-style ssh git urls to https without embedding custom ports in the path', function (string $url, string $expected) {
    expect(scpStyleGitUrlToHttps($url))->toBe($expected);
})->with([
    'git username' => [
        'git@github.com:organization/repository.git',
        'https://github.com/organization/repository.git',
    ],
    'custom username' => [
        'custom-user@git.example.com:organization/repository.git',
        'https://git.example.com/organization/repository.git',
    ],
    'custom username and port' => [
        'custom-user@git.example.com:2222/organization/repository.git',
        'https://git.example.com/organization/repository.git',
    ],
]);

it('rejects non-scp-style git urls', function (string $url) {
    expect(parseScpStyleGitUrl($url))->toBeNull()
        ->and(scpStyleGitUrlToHttps($url))->toBeNull();
})->with([
    'https' => 'https://github.com/organization/repository.git',
    'email without path' => 'custom-user@git.example.com',
    'ssh scheme' => 'ssh://git@github.com/organization/repository.git',
    'empty' => '',
]);

it('normalizes github app repository slugs from scp-style ssh urls', function (string $url, string $expected) {
    expect(gitRepositorySlug($url))->toBe($expected);
})->with([
    'https' => ['https://github.com/organization/repository.git', 'organization/repository'],
    'owner/repo' => ['organization/repository', 'organization/repository'],
    'git username' => ['git@github.com:organization/repository.git', 'organization/repository'],
    'custom username' => ['custom-user@git.example.com:organization/repository.git', 'organization/repository'],
    'custom username and port' => ['custom-user@git.example.com:2222/organization/repository.git', 'organization/repository'],
]);
