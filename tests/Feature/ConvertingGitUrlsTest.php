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

test('convertGitUrlsForSourceAndSshUrlSchemeWithCustomPort', function () {
    $result = convertGitUrl('ssh://git@192.168.56.11:22222/User/Repo.git', 'source', null);
    expect($result)->toBe([
        'repository' => 'ssh://git@192.168.56.11:22222/User/Repo.git',
        'port' => '22222',
    ]);
});

test('convertGitUrlsForSourceAndSshUrlSchemeWithCustomPortAndIpv6Host', function () {
    $result = convertGitUrl('ssh://git@[2001:db8::10]:22222/group/project.git', 'source', null);
    expect($result)->toBe([
        'repository' => 'ssh://git@[2001:db8::10]:22222/group/project.git',
        'port' => '22222',
    ]);
});

test('convertGitUrlsForDeployKeyAndGithubAppWithCustomPort', function () {
    $githubApp = new GithubApp([
        'html_url' => 'https://github.example.com',
        'custom_user' => 'git',
        'custom_port' => 22222,
    ]);

    $result = convertGitUrl('andrasbacsai/coolify-examples.git', 'deploy_key', $githubApp);
    expect($result)->toBe([
        'repository' => 'ssh://git@github.example.com:22222/andrasbacsai/coolify-examples.git',
        'port' => '22222',
    ]);
});

test('convertGitUrlsForDeployKeyAndGithubAppWithCustomPortAndIpv6Host', function () {
    $githubApp = new GithubApp([
        'html_url' => 'https://[2001:db8::10]',
        'custom_user' => 'git',
        'custom_port' => 22222,
    ]);

    $result = convertGitUrl('andrasbacsai/coolify-examples.git', 'deploy_key', $githubApp);
    expect($result)->toBe([
        'repository' => 'ssh://git@[2001:db8::10]:22222/andrasbacsai/coolify-examples.git',
        'port' => '22222',
    ]);
});
