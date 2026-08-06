<?php

use App\Models\Application;
use App\Models\ApplicationSetting;
use App\Models\GithubApp;
use App\Models\PrivateKey;

function applicationWithGitSettings(bool $shallow = true): Application
{
    $application = new Application;
    $application->forceFill([
        'uuid' => 'test-app-uuid',
        'git_repository' => 'coollabsio/private-app',
        'git_branch' => 'main',
        'git_commit_sha' => 'HEAD',
    ]);

    $settings = new ApplicationSetting;
    $settings->is_git_shallow_clone_enabled = $shallow;
    $settings->is_git_submodules_enabled = false;
    $settings->is_git_lfs_enabled = false;
    $application->setRelation('settings', $settings);

    return $application;
}

it('uses http 1 transport for public https source clones', function () {
    $application = applicationWithGitSettings();

    $source = new GithubApp;
    $source->forceFill([
        'html_url' => 'https://github.com',
        'api_url' => 'https://api.github.com',
        'is_public' => true,
    ]);
    $application->setRelation('source', $source);

    $result = $application->generateGitImportCommands(
        deployment_uuid: 'test-deployment',
        exec_in_docker: false,
    );

    expect($result['commands'])
        ->toContain("git -c http.version=HTTP/1.1 clone --depth=1 -b 'main' 'https://github.com/coollabsio/private-app' '/artifacts/test-deployment'")
        ->not->toContain('Primary repository import failed, retrying with HTTP/1.1')
        ->not->toContain('mktemp')
        ->not->toContain('git_retry_dir');
});

it('applies http 1 transport to https fetches after clone', function () {
    $application = applicationWithGitSettings();
    $application->git_commit_sha = 'abc123def456abc123def456abc123def456abc1';

    $source = new GithubApp;
    $source->forceFill([
        'html_url' => 'https://github.com',
        'api_url' => 'https://api.github.com',
        'is_public' => true,
    ]);
    $application->setRelation('source', $source);

    $result = $application->generateGitImportCommands(
        deployment_uuid: 'test-deployment',
        exec_in_docker: false,
    );

    expect($result['commands'])
        ->toContain("git -c http.version=HTTP/1.1 fetch --depth=1 origin 'abc123def456abc123def456abc123def456abc1'")
        ->toContain("git -c http.version=HTTP/1.1 -c advice.detachedHead=false checkout 'abc123def456abc123def456abc123def456abc1'");
});

it('does not add http transport config to ssh deploy key clones', function () {
    $application = applicationWithGitSettings();
    $application->private_key_id = 1;
    $application->setRelation('private_key', new class extends PrivateKey
    {
        public function getAttribute($key)
        {
            if ($key === 'private_key') {
                return 'fake-private-key';
            }

            return parent::getAttribute($key);
        }
    });
    $application->git_repository = 'git@github.com:coollabsio/private-app.git';

    $result = $application->generateGitImportCommands(
        deployment_uuid: 'test-deployment',
        exec_in_docker: false,
    );

    expect($result['commands'])
        ->not->toContain('http.version=HTTP/1.1')
        ->not->toContain('Primary repository import failed, retrying with HTTP/1.1');
});

it('supports dedicated checkout directories for compose file loading', function () {
    $application = applicationWithGitSettings();

    $source = new GithubApp;
    $source->forceFill([
        'html_url' => 'https://github.com',
        'api_url' => 'https://api.github.com',
        'is_public' => true,
    ]);
    $application->setRelation('source', $source);

    $result = $application->generateGitImportCommands(
        deployment_uuid: 'test-deployment',
        only_checkout: true,
        exec_in_docker: false,
        custom_base_dir: 'checkout',
    );

    expect($result['commands'])
        ->toContain("git -c http.version=HTTP/1.1 clone --depth=1 --no-checkout -b 'main' 'https://github.com/coollabsio/private-app' 'checkout'")
        ->not->toContain('mktemp')
        ->not->toContain('git_retry_dir');
});

it('applies http 1 transport to custom bitbucket pull request checkout', function () {
    $application = applicationWithGitSettings();
    $application->git_repository = 'https://bitbucket.org/coollabsio/private-app.git';

    $result = $application->generateGitImportCommands(
        deployment_uuid: 'test-deployment',
        pull_request_id: 123,
        git_type: 'bitbucket',
        exec_in_docker: false,
        commit: 'abc123def456abc123def456abc123def456abc1',
    );

    expect($result['commands'])
        ->toContain("git -c http.version=HTTP/1.1 checkout 'abc123def456abc123def456abc123def456abc1'");
});
