<?php

use App\Models\Application;
use App\Models\ApplicationSetting;

describe('Application Rollback', function () {
    beforeEach(function () {
        $this->application = new Application;
        $this->application->forceFill([
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
});
