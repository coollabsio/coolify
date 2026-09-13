<?php

use App\Models\Application;
use App\Models\GithubApp;

it('treats git-hosted applications as git based', function (string $buildPack) {
    $application = new Application([
        'build_pack' => $buildPack,
        'dockerfile' => null,
    ]);

    expect($application->git_based())->toBeTrue();
})->with([
    'nixpacks',
    'railpack',
    'static',
    'dockerfile',
    'dockercompose',
]);

it('does not treat inline dockerfile applications as git based', function () {
    $application = new Application([
        'build_pack' => 'dockerfile',
        'dockerfile' => 'FROM nginx',
    ]);

    expect($application->git_based())->toBeFalse();
});

it('does not treat docker image applications as git based', function () {
    $application = new Application([
        'build_pack' => 'dockerimage',
        'dockerfile' => null,
    ]);

    expect($application->git_based())->toBeFalse();
});

it('treats applications without a source integration as not github based', function () {
    $application = new Application;
    $application->setRelation('source', null);

    expect($application->is_github_based())->toBeFalse()
        ->and($application->is_public_repository())->toBeFalse();
});

it('treats applications with a github app source as github based', function () {
    $application = new Application;
    $application->setRelation('source', new GithubApp(['is_public' => false]));

    expect($application->is_github_based())->toBeTrue()
        ->and($application->is_public_repository())->toBeFalse();
});

it('detects public github source repositories independently of git_based', function () {
    $application = new Application([
        'build_pack' => 'nixpacks',
        'dockerfile' => null,
    ]);
    $application->setRelation('source', new GithubApp(['is_public' => true]));

    expect($application->git_based())->toBeTrue()
        ->and($application->is_github_based())->toBeTrue()
        ->and($application->is_public_repository())->toBeTrue();
});
