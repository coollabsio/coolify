<?php

use App\Models\Application;
use App\Models\ApplicationSetting;
use App\Models\GithubApp;

beforeEach(function () {
    $this->application = new Application;
    $this->application->forceFill([
        'uuid' => 'test-app-uuid',
        'git_repository' => 'coollabsio/coolify',
        'git_branch' => 'main',
        'git_commit_sha' => 'HEAD',
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

it('uses HTTP/1.1 for HTTPS GitHub app clone commands', function () {
    $result = $this->application->generateGitImportCommands(
        deployment_uuid: 'test-uuid',
        exec_in_docker: false,
        only_checkout: true,
        custom_base_dir: '.',
    );

    expect($result['commands'])
        ->toContain("git -c 'http.version=HTTP/1.1' clone --no-checkout -b 'main'")
        ->toContain("'https://github.com/coollabsio/coolify' '.'");
});

it('keeps SSH clone commands on the default git transport', function () {
    $application = new Application;
    $application->forceFill([
        'uuid' => 'test-app-uuid',
        'git_repository' => 'git@github.com:coollabsio/coolify.git',
        'git_branch' => 'main',
        'git_commit_sha' => 'HEAD',
    ]);

    $settings = new ApplicationSetting;
    $settings->is_git_shallow_clone_enabled = false;
    $settings->is_git_submodules_enabled = false;
    $settings->is_git_lfs_enabled = false;
    $application->setRelation('settings', $settings);

    $result = $application->generateGitImportCommands(
        deployment_uuid: 'test-uuid',
        exec_in_docker: false,
    );

    expect($result['commands'])
        ->toContain("git clone -b 'main'")
        ->not->toContain('http.version=HTTP/1.1');
});

it('uses HTTP/1.1 for follow-up fetch checkout submodule and LFS commands', function () {
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

it('uses HTTP/1.1 when fetching GitHub pull request refs over HTTPS', function () {
    $result = $this->application->generateGitImportCommands(
        deployment_uuid: 'test-uuid',
        pull_request_id: 42,
        exec_in_docker: false,
    );

    expect($result['commands'])
        ->toContain("git -c 'http.version=HTTP/1.1' clone -b 'main'")
        ->toContain("git -c 'http.version=HTTP/1.1' fetch origin 'pull/42/head:pr-42-coolify'")
        ->toContain("git -c 'http.version=HTTP/1.1' -c 'advice.detachedHead=false' checkout 'pr-42-coolify'");
});
