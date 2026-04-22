<?php

use App\Models\Application;
use App\Models\ApplicationSetting;
use App\Models\GithubApp;

beforeEach(function () {
    $this->application = new Application;
    $this->application->fill([
        'uuid' => 'test-app-uuid',
        'git_repository' => 'coollabsio/coolify',
        'git_branch' => 'main',
        'git_commit_sha' => 'HEAD',
        'source_type' => GithubApp::class,
        'source_id' => 1,
    ]);

    $settings = new ApplicationSetting;
    $settings->is_git_shallow_clone_enabled = false;
    $settings->is_git_submodules_enabled = false;
    $settings->is_git_lfs_enabled = false;

    $this->application->setRelation('settings', $settings);
    $this->application->setRelation('source', new GithubApp([
        'html_url' => 'https://github.com',
        'is_public' => true,
    ]));
});

test('generateGitImportCommands uses HTTP/1.1 for public github source checkout clones', function () {
    $result = $this->application->generateGitImportCommands(
        deployment_uuid: 'test-uuid',
        exec_in_docker: false,
        only_checkout: true,
        custom_base_dir: '/tmp/test-checkout'
    );

    expect($result['commands'])
        ->toContain("git -c 'http.version=HTTP/1.1' clone --no-checkout -b 'main'")
        ->toContain("'https://github.com/coollabsio/coolify'")
        ->toContain("'/tmp/test-checkout'");
});

test('setGitImportSettings applies HTTP/1.1 to the full git bootstrap sequence', function () {
    $this->application->settings->is_git_shallow_clone_enabled = true;
    $this->application->settings->is_git_submodules_enabled = true;
    $this->application->settings->is_git_lfs_enabled = true;

    $result = $this->application->setGitImportSettings(
        deployment_uuid: 'test-uuid',
        git_clone_command: 'git clone',
        public: true,
        commit: 'abc123def456abc123def456abc123def456abc1',
        forceHttpVersionOne: true,
    );

    expect($result)
        ->toContain("git -c 'http.version=HTTP/1.1' fetch --depth=1 origin 'abc123def456abc123def456abc123def456abc1'")
        ->toContain("git -c 'http.version=HTTP/1.1' -c 'advice.detachedHead=false' checkout 'abc123def456abc123def456abc123def456abc1'")
        ->toContain("git -c 'http.version=HTTP/1.1' submodule sync")
        ->toContain("git -c 'http.version=HTTP/1.1' submodule update --init --recursive --depth=1")
        ->toContain("git -c 'http.version=HTTP/1.1' lfs pull");
});

test('generateGitImportCommands uses HTTP/1.1 when fetching github pull request refs over https', function () {
    $result = $this->application->generateGitImportCommands(
        deployment_uuid: 'test-uuid',
        pull_request_id: 42,
        exec_in_docker: false,
    );

    expect($result['commands'])
        ->toContain("git -c 'http.version=HTTP/1.1' clone -b 'main'")
        ->toContain("git -c 'http.version=HTTP/1.1' fetch origin 'pull/42/head:pr-42-coolify'");
});
