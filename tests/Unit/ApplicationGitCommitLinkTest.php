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
    'SSH URL' => [
        'ssh://git@gitlab.com/coollabsio/coolify.git',
        'https://gitlab.com/coollabsio/coolify/commit/1234567890abcdef',
    ],
    'Bitbucket HTTPS remote' => [
        'https://bitbucket.org/coollabsio/coolify.git',
        'https://bitbucket.org/coollabsio/coolify/commits/1234567890abcdef',
    ],
]);
