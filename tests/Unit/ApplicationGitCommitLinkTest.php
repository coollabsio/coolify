<?php

use App\Models\Application;

it('generates commit links for direct repository remotes', function (string $repository, string $expected) {
    $application = new Application;
    $application->setRelation('source', null);
    $application->git_repository = $repository;

    expect($application->gitCommitLink('1234567890abcdef'))->toBe($expected);
})->with([
    'HTTPS remote' => [
        'https://github.com/coollabsio/coolify.git',
        'https://github.com/coollabsio/coolify/commit/1234567890abcdef',
    ],
    'SSH remote' => [
        'git@github.com:coollabsio/coolify.git',
        'https://github.com/coollabsio/coolify/commit/1234567890abcdef',
    ],
    'SSH remote with custom username' => [
        'custom-user@git.example.com:coollabsio/coolify.git',
        'https://git.example.com/coollabsio/coolify/commit/1234567890abcdef',
    ],
    'SSH remote with custom username and port' => [
        'custom-user@git.example.com:2222/coollabsio/coolify.git',
        'https://git.example.com/coollabsio/coolify/commit/1234567890abcdef',
    ],
    'SSH URL' => [
        'ssh://git@gitlab.com/coollabsio/coolify.git',
        'https://gitlab.com/coollabsio/coolify/commit/1234567890abcdef',
    ],
    'Bitbucket HTTPS remote' => [
        'https://bitbucket.org/coollabsio/coolify.git',
        'https://bitbucket.org/coollabsio/coolify/commits/1234567890abcdef',
    ],
]);

it('does not generate commit links from incomplete repository URLs', function (string $repository) {
    $application = new Application;
    $application->setRelation('source', null);
    $application->git_repository = $repository;

    expect($application->gitCommitLink('1234567890abcdef'))->toBeNull();
})->with([
    'missing host' => 'https://',
    'missing scheme' => 'github.com/coollabsio/coolify',
]);

it('converts scp-style remotes with generic usernames into https repository links', function (string $repository, string $expectedBranch, string $expectedCommits, string $expectedWebhook) {
    $application = new Application;
    $application->setRelation('source', null);
    $application->git_repository = $repository;
    $application->git_branch = 'main';
    $application->base_directory = '/';

    expect($application->gitBranchLocation)->toBe($expectedBranch)
        ->and($application->gitCommits)->toBe($expectedCommits)
        ->and($application->gitWebhook)->toBe($expectedWebhook);
})->with([
    'git username' => [
        'git@github.com:coollabsio/coolify.git',
        'https://github.com/coollabsio/coolify/tree/main/',
        'https://github.com/coollabsio/coolify/commits/main',
        'https://github.com/coollabsio/coolify/settings/hooks',
    ],
    'custom username' => [
        'custom-user@git.example.com:organization/repository.git',
        'https://git.example.com/organization/repository/tree/main/',
        'https://git.example.com/organization/repository/commits/main',
        'https://git.example.com/organization/repository/settings/hooks',
    ],
    'custom username and port' => [
        'custom-user@git.example.com:2222/organization/repository.git',
        'https://git.example.com/organization/repository/tree/main/',
        'https://git.example.com/organization/repository/commits/main',
        'https://git.example.com/organization/repository/settings/hooks',
    ],
]);
