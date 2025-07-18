<?php

use App\Models\GithubApp;

test('convertGitUrlsForDeployKeyAndGithubAppAndHttpUrl', function () {
    $githubApp = GithubApp::find(0);
    $result = convertGitUrl('andrasbacsai/coolify-examples.git', 'deploy_key', $githubApp);
    expect($result)->toBe([
        'repository' => 'git@github.com:andrasbacsai/coolify-examples.git',
        'port' => 22,
    ]);

});

test('convertGitUrlsForDeployKeyAndGithubAppAndSshUrl', function () {
    $githubApp = GithubApp::find(0);
    $result = convertGitUrl('git@github.com:andrasbacsai/coolify-examples.git', 'deploy_key', $githubApp);
    expect($result)->toBe([
        'repository' => 'git@github.com:andrasbacsai/coolify-examples.git',
        'port' => 22,
    ]);
});

test('convertGitUrlsForDeployKeyAndHttpUrl', function () {
    $result = convertGitUrl('andrasbacsai/coolify-examples.git', 'deploy_key', null);
    expect($result)->toBe([
        'repository' => 'andrasbacsai/coolify-examples.git',
        'port' => 22,
    ]);
});

test('convertGitUrlsForDeployKeyAndSshUrl', function () {
    $result = convertGitUrl('git@github.com:andrasbacsai/coolify-examples.git', 'deploy_key', null);
    expect($result)->toBe([
        'repository' => 'git@github.com:andrasbacsai/coolify-examples.git',
        'port' => 22,
    ]);
});

test('convertGitUrlsForSourceAndSshUrl', function () {
    $result = convertGitUrl('git@github.com:andrasbacsai/coolify-examples.git', 'source', null);
    expect($result)->toBe([
        'repository' => 'git@github.com:andrasbacsai/coolify-examples.git',
        'port' => 22,
    ]);
});

test('convertGitUrlsForSourceAndHttpUrl', function () {
    $result = convertGitUrl('andrasbacsai/coolify-examples.git', 'source', null);
    expect($result)->toBe([
        'repository' => 'andrasbacsai/coolify-examples.git',
        'port' => 22,
    ]);
});

test('convertGitUrlsForSourceAndSshUrlWithCustomPort', function () {
    $result = convertGitUrl('git@git.domain.com:766/group/project.git', 'source', null);
    expect($result)->toBe([
        'repository' => 'git@git.domain.com:group/project.git',
        'port' => '766',
    ]);
});
