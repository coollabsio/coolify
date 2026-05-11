<?php

use App\Models\Application;
use App\Models\ApplicationSetting;

describe('Application Rollback', function () {
    beforeEach(function () {
        $this->application = new Application;
        $this->application->fill([
            'uuid' => 'test-app-uuid',
            'git_commit_sha' => 'HEAD',
        ]);

        $settings = new ApplicationSetting;
        $settings->is_git_shallow_clone_enabled = false;
        $settings->is_git_submodules_enabled = false;
        $settings->is_git_lfs_enabled = false;
        $this->application->setRelation('settings', $settings);
    });

    test('setGitImportSettings uses passed commit instead of application git_commit_sha', function () {
        $rollbackCommit = 'abc123def456abc123def456abc123def456abc1';

        $result = $this->application->setGitImportSettings(
            deployment_uuid: 'test-uuid',
            git_clone_command: 'git clone',
            public: true,
            commit: $rollbackCommit
        );

        expect($result)->toContain($rollbackCommit);
    });

    test('setGitImportSettings with shallow clone fetches specific commit', function () {
        $this->application->settings->is_git_shallow_clone_enabled = true;

        $rollbackCommit = 'abc123def456abc123def456abc123def456abc1';

        $result = $this->application->setGitImportSettings(
            deployment_uuid: 'test-uuid',
            git_clone_command: 'git clone',
            public: true,
            commit: $rollbackCommit
        );

        expect($result)
            ->toContain('git fetch --depth=1 origin')
            ->toContain($rollbackCommit);
    });

    test('setGitImportSettings falls back to git_commit_sha when no commit passed', function () {
        $this->application->git_commit_sha = 'def789abc012def789abc012def789abc012def7';

        $result = $this->application->setGitImportSettings(
            deployment_uuid: 'test-uuid',
            git_clone_command: 'git clone',
            public: true,
        );

        expect($result)->toContain('def789abc012def789abc012def789abc012def7');
    });

    test('setGitImportSettings escapes shell metacharacters in commit parameter', function () {
        $maliciousCommit = 'abc123; rm -rf /';

        $result = $this->application->setGitImportSettings(
            deployment_uuid: 'test-uuid',
            git_clone_command: 'git clone',
            public: true,
            commit: $maliciousCommit
        );

        // escapeshellarg wraps the value in single quotes, neutralizing metacharacters
        expect($result)
            ->toContain("checkout 'abc123; rm -rf /'")
            ->not->toContain('checkout abc123; rm -rf /');
    });

    test('setGitImportSettings does not append checkout when commit is HEAD', function () {
        $result = $this->application->setGitImportSettings(
            deployment_uuid: 'test-uuid',
            git_clone_command: 'git clone',
            public: true,
        );

        expect($result)->not->toContain('advice.detachedHead=false checkout');
    });

    test('setGitImportSettings uses provided git_ssh_command for fetch', function () {
        $this->application->settings->is_git_shallow_clone_enabled = true;
        $rollbackCommit = 'abc123def456abc123def456abc123def456abc1';
        $sshCommand = 'GIT_SSH_COMMAND="ssh -o ConnectTimeout=30 -p 22222 -o Port=22222 -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i /root/.ssh/id_rsa"';

        $result = $this->application->setGitImportSettings(
            deployment_uuid: 'test-uuid',
            git_clone_command: 'git clone',
            commit: $rollbackCommit,
            git_ssh_command: $sshCommand,
        );

        expect($result)
            ->toContain('-i /root/.ssh/id_rsa" git fetch --depth=1 origin')
            ->toContain($rollbackCommit);
    });

    test('setGitImportSettings uses provided git_ssh_command for submodule update', function () {
        $this->application->settings->is_git_submodules_enabled = true;
        $sshCommand = 'GIT_SSH_COMMAND="ssh -o ConnectTimeout=30 -p 22 -o Port=22 -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i /root/.ssh/id_rsa"';

        $result = $this->application->setGitImportSettings(
            deployment_uuid: 'test-uuid',
            git_clone_command: 'git clone',
            git_ssh_command: $sshCommand,
        );

        expect($result)
            ->toContain('-i /root/.ssh/id_rsa" git submodule update --init --recursive');
    });

    test('setGitImportSettings uses provided git_ssh_command for lfs pull', function () {
        $this->application->settings->is_git_lfs_enabled = true;
        $sshCommand = 'GIT_SSH_COMMAND="ssh -o ConnectTimeout=30 -p 22 -o Port=22 -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i /root/.ssh/id_rsa"';

        $result = $this->application->setGitImportSettings(
            deployment_uuid: 'test-uuid',
            git_clone_command: 'git clone',
            git_ssh_command: $sshCommand,
        );

        expect($result)->toContain('-i /root/.ssh/id_rsa" git lfs pull');
    });

    test('setGitImportSettings uses default ssh command when git_ssh_command not provided', function () {
        $this->application->settings->is_git_lfs_enabled = true;

        $result = $this->application->setGitImportSettings(
            deployment_uuid: 'test-uuid',
            git_clone_command: 'git clone',
            public: true,
        );

        expect($result)
            ->toContain('GIT_SSH_COMMAND="ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null" git lfs pull')
            ->not->toContain('-i /root/.ssh/id_rsa');
    });
});
