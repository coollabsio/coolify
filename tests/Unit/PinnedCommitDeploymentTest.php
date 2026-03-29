<?php

use App\Models\Application;
use App\Models\ApplicationSetting;

describe('Pinned commit propagation', function () {
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

    test('setGitImportSettings uses pinned commit when no explicit commit is passed', function () {
        $pinnedSha = str_repeat('a', 40);
        $this->application->git_commit_sha = $pinnedSha;

        $result = $this->application->setGitImportSettings(
            deployment_uuid: 'test-uuid',
            git_clone_command: 'git clone',
            public: true,
        );

        expect($result)->toContain($pinnedSha);
    });

    test('setGitImportSettings prefers explicit commit over pinned commit', function () {
        $pinnedSha = str_repeat('a', 40);
        $explicitSha = str_repeat('b', 40);
        $this->application->git_commit_sha = $pinnedSha;

        $result = $this->application->setGitImportSettings(
            deployment_uuid: 'test-uuid',
            git_clone_command: 'git clone',
            public: true,
            commit: $explicitSha,
        );

        expect($result)
            ->toContain($explicitSha)
            ->not->toContain($pinnedSha);
    });

    test('setGitImportSettings does not checkout when git_commit_sha is HEAD', function () {
        $this->application->git_commit_sha = 'HEAD';

        $result = $this->application->setGitImportSettings(
            deployment_uuid: 'test-uuid',
            git_clone_command: 'git clone',
            public: true,
        );

        expect($result)->not->toContain('advice.detachedHead=false checkout');
    });

});
