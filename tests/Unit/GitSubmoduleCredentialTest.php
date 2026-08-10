<?php

use App\Models\Application;
use App\Models\ApplicationSetting;
use App\Models\GitlabApp;
use App\Models\PrivateKey;

describe('Git submodule credential propagation', function () {
    beforeEach(function () {
        $this->application = new Application;
        $this->application->forceFill([
            'uuid' => 'test-app-uuid',
            'git_commit_sha' => 'HEAD',
        ]);

        $settings = new ApplicationSetting;
        $settings->is_git_shallow_clone_enabled = false;
        $settings->is_git_submodules_enabled = true;
        $settings->is_git_lfs_enabled = false;
        $this->application->setRelation('settings', $settings);
    });

    test('setGitImportSettings uses provided gitSshCommand for submodule update', function () {
        $sshCommand = 'ssh -o ConnectTimeout=30 -p 22 -o Port=22 -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i /root/.ssh/id_rsa';

        $result = $this->application->setGitImportSettings(
            deployment_uuid: 'test-uuid',
            git_clone_command: 'git clone',
            public: false,
            gitSshCommand: $sshCommand
        );

        expect($result)
            ->toContain('GIT_SSH_COMMAND="'.$sshCommand.'" git submodule update --init --recursive')
            ->toContain('git submodule sync');
    });

    test('setGitImportSettings uses default ssh command when no gitSshCommand provided', function () {
        $result = $this->application->setGitImportSettings(
            deployment_uuid: 'test-uuid',
            git_clone_command: 'git clone',
            public: false,
        );

        expect($result)
            ->toContain('GIT_SSH_COMMAND="ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null" git submodule update --init --recursive');
    });

    test('setGitImportSettings uses provided gitSshCommand for fetch and checkout', function () {
        $this->application->git_commit_sha = 'abc123def456';
        $sshCommand = 'ssh -o ConnectTimeout=30 -p 22 -o Port=22 -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i /root/.ssh/id_rsa';

        $result = $this->application->setGitImportSettings(
            deployment_uuid: 'test-uuid',
            git_clone_command: 'git clone',
            public: false,
            gitSshCommand: $sshCommand
        );

        expect($result)
            ->toContain('GIT_SSH_COMMAND="'.$sshCommand.'" git -c advice.detachedHead=false checkout');
    });

    test('setGitImportSettings uses provided gitSshCommand for shallow fetch', function () {
        $this->application->git_commit_sha = 'abc123def456';
        $this->application->settings->is_git_shallow_clone_enabled = true;
        $sshCommand = 'ssh -o ConnectTimeout=30 -p 22 -o Port=22 -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i /root/.ssh/id_rsa';

        $result = $this->application->setGitImportSettings(
            deployment_uuid: 'test-uuid',
            git_clone_command: 'git clone',
            public: false,
            gitSshCommand: $sshCommand
        );

        expect($result)
            ->toContain('GIT_SSH_COMMAND="'.$sshCommand.'" git fetch --depth=1 origin');
    });

    test('setGitImportSettings uses provided gitSshCommand for lfs pull', function () {
        $this->application->settings->is_git_lfs_enabled = true;
        $sshCommand = 'ssh -o ConnectTimeout=30 -p 22 -i /root/.ssh/id_rsa';

        $result = $this->application->setGitImportSettings(
            deployment_uuid: 'test-uuid',
            git_clone_command: 'git clone',
            public: false,
            gitSshCommand: $sshCommand
        );

        expect($result)
            ->toContain('GIT_SSH_COMMAND="'.$sshCommand.'" git lfs pull');
    });

    test('buildGitCheckoutCommand includes GIT_SSH_COMMAND for submodule update when provided', function () {
        $sshCommand = 'ssh -o ConnectTimeout=30 -p 22 -i /root/.ssh/id_rsa';

        $method = new ReflectionMethod($this->application, 'buildGitCheckoutCommand');
        $result = $method->invoke($this->application, 'main', $sshCommand);

        expect($result)
            ->toContain("git checkout 'main'")
            ->toContain('GIT_SSH_COMMAND="'.$sshCommand.'" git submodule update --init --recursive');
    });

    test('buildGitCheckoutCommand uses default ssh command for submodule update when none provided', function () {
        $method = new ReflectionMethod($this->application, 'buildGitCheckoutCommand');
        $result = $method->invoke($this->application, 'main');

        expect($result)
            ->toContain('GIT_SSH_COMMAND="ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null" git submodule update --init --recursive');
    });

    test('buildGitCheckoutCommand omits submodule update when submodules disabled', function () {
        $this->application->settings->is_git_submodules_enabled = false;

        $method = new ReflectionMethod($this->application, 'buildGitCheckoutCommand');
        $result = $method->invoke($this->application, 'main');

        expect($result)
            ->toContain("git checkout 'main'")
            ->not->toContain('submodule');
    });

    test('generateGitImportCommands uses GitLab private key for PR submodule checkout', function () {
        $settings = new ApplicationSetting;
        $settings->is_git_shallow_clone_enabled = false;
        $settings->is_git_submodules_enabled = true;
        $settings->is_git_lfs_enabled = false;

        $privateKey = Mockery::mock(PrivateKey::class)->makePartial();
        $privateKey->shouldReceive('getAttribute')->with('private_key')->andReturn('fake-private-key');

        $gitlabSource = Mockery::mock(GitlabApp::class)->makePartial();
        $gitlabSource->shouldReceive('getMorphClass')->andReturn(GitlabApp::class);
        $gitlabSource->shouldReceive('getAttribute')->with('privateKey')->andReturn($privateKey);
        $gitlabSource->shouldReceive('getAttribute')->with('custom_port')->andReturn(22);
        $gitlabSource->shouldReceive('getAttribute')->with('html_url')->andReturn('https://gitlab.com');

        $application = Mockery::mock(Application::class)->makePartial();
        $application->git_branch = 'main';
        $application->git_commit_sha = 'HEAD';
        $application->setRelation('settings', $settings);
        $application->source = $gitlabSource;
        $application->shouldReceive('deploymentType')->andReturn('source');
        $application->shouldReceive('customRepository')->andReturn([
            'repository' => 'git@gitlab.com:user/repo.git',
            'port' => 22,
        ]);
        $application->shouldReceive('getAttribute')->with('source')->andReturn($gitlabSource);

        $result = $application->generateGitImportCommands(
            deployment_uuid: 'test-uuid',
            pull_request_id: 123,
            git_type: 'gitlab',
            exec_in_docker: false,
        );

        $sshCommand = 'ssh -o ConnectTimeout=30 -p 22 -o Port=22 -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i /root/.ssh/id_rsa_coolify_test-uuid -o IdentitiesOnly=yes';

        expect($result['commands'])
            ->toContain('GIT_SSH_COMMAND="'.$sshCommand.'" git fetch origin merge-requests/123/head:pr-123-coolify')
            ->toContain("git checkout 'pr-123-coolify'")
            ->toContain('GIT_SSH_COMMAND="'.$sshCommand.'" git submodule update --init --recursive')
            ->not->toContain('GIT_SSH_COMMAND="ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null" git submodule update --init --recursive');
    });

});
