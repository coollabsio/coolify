<?php

use App\Models\Application;
use App\Models\ApplicationSetting;
use App\Models\GitlabApp;
use App\Models\PrivateKey;

afterEach(function () {
    Mockery::close();
});

/**
 * Git operations authenticate with the SSH key assigned in the UI. Coolify writes that key to a
 * per-deployment path (/root/.ssh/id_rsa_coolify_<deployment_uuid>) instead of the shared
 * /root/.ssh/id_rsa, so it can neither overwrite the server root's own key nor race with other
 * concurrent operations on the same host. `-o IdentitiesOnly=yes` makes ssh offer only that key.
 * On the host path an EXIT trap removes the key when the shell finishes; the docker path runs in an
 * ephemeral container, so it needs no cleanup.
 */
$keyPath = '/root/.ssh/id_rsa_coolify_test-deployment-uuid';

it('writes a deploy key to a per-deployment path and cleans it up for ls-remote on the host', function () use ($keyPath) {
    $privateKey = Mockery::mock(PrivateKey::class)->makePartial();
    $privateKey->shouldReceive('getAttribute')->with('private_key')->andReturn('fake-private-key');

    $application = Mockery::mock(Application::class)->makePartial();
    $application->git_branch = 'main';
    $application->shouldReceive('deploymentType')->andReturn('deploy_key');
    $application->shouldReceive('customRepository')->andReturn(['repository' => 'git@gitlab.com:user/repo.git', 'port' => 22]);
    $application->shouldReceive('getAttribute')->with('private_key')->andReturn($privateKey);

    $result = $application->generateGitLsRemoteCommands('test-deployment-uuid', false);

    expect($result['commands'])
        ->toContain("tee {$keyPath}")
        ->toContain("-i {$keyPath} -o IdentitiesOnly=yes")
        ->toContain("trap 'rm -f {$keyPath}' EXIT") // removed when the shell exits
        ->not->toContain('tee /root/.ssh/id_rsa >'); // never overwrites the host root's own key
});

it('writes a deploy key to a per-deployment path for ls-remote inside docker without a trap', function () use ($keyPath) {
    $privateKey = Mockery::mock(PrivateKey::class)->makePartial();
    $privateKey->shouldReceive('getAttribute')->with('private_key')->andReturn('fake-private-key');

    $application = Mockery::mock(Application::class)->makePartial();
    $application->git_branch = 'main';
    $application->shouldReceive('deploymentType')->andReturn('deploy_key');
    $application->shouldReceive('customRepository')->andReturn(['repository' => 'git@gitlab.com:user/repo.git', 'port' => 22]);
    $application->shouldReceive('getAttribute')->with('private_key')->andReturn($privateKey);

    $result = $application->generateGitLsRemoteCommands('test-deployment-uuid', true);

    expect($result['commands'])
        ->toContain("tee {$keyPath}")
        ->toContain("-i {$keyPath} -o IdentitiesOnly=yes")
        ->not->toContain('trap ') // ephemeral container, no cleanup needed
        ->not->toContain('tee /root/.ssh/id_rsa >');
});

it('writes a GitLab source private key to a per-deployment path with cleanup on the host', function () use ($keyPath) {
    $privateKey = Mockery::mock(PrivateKey::class)->makePartial();
    $privateKey->shouldReceive('getAttribute')->with('private_key')->andReturn('fake-private-key');

    $gitlabSource = Mockery::mock(GitlabApp::class)->makePartial();
    $gitlabSource->shouldReceive('getMorphClass')->andReturn(GitlabApp::class);
    $gitlabSource->shouldReceive('getAttribute')->with('html_url')->andReturn('https://gitlab.com');
    $gitlabSource->shouldReceive('getAttribute')->with('privateKey')->andReturn($privateKey);
    $gitlabSource->shouldReceive('getAttribute')->with('private_key_id')->andReturn(1);
    $gitlabSource->shouldReceive('getAttribute')->with('custom_port')->andReturn(22);

    $application = Mockery::mock(Application::class)->makePartial();
    $application->git_branch = 'main';
    $application->shouldReceive('deploymentType')->andReturn('source');
    $application->shouldReceive('customRepository')->andReturn(['repository' => 'git@gitlab.com:user/repo.git', 'port' => 22]);
    $application->shouldReceive('getAttribute')->with('source')->andReturn($gitlabSource);
    $application->source = $gitlabSource;

    $result = $application->generateGitLsRemoteCommands('test-deployment-uuid', false);

    expect($result['commands'])
        ->toContain("tee {$keyPath}")
        ->toContain("-i {$keyPath} -o IdentitiesOnly=yes")
        ->toContain("trap 'rm -f {$keyPath}' EXIT")
        ->not->toContain('tee /root/.ssh/id_rsa >');
});

it('writes a deploy key to a per-deployment path and cleans it up when cloning on the host', function () use ($keyPath) {
    $privateKey = Mockery::mock(PrivateKey::class)->makePartial();
    $privateKey->shouldReceive('getAttribute')->with('private_key')->andReturn('fake-private-key');

    $settings = Mockery::mock(ApplicationSetting::class)->makePartial();
    $settings->shouldReceive('getAttribute')->with('is_git_shallow_clone_enabled')->andReturn(false);
    $settings->shouldReceive('getAttribute')->with('is_git_submodules_enabled')->andReturn(false);
    $settings->shouldReceive('getAttribute')->with('is_git_lfs_enabled')->andReturn(false);

    $application = Mockery::mock(Application::class)->makePartial();
    $application->git_branch = 'main';
    $application->shouldReceive('deploymentType')->andReturn('deploy_key');
    $application->shouldReceive('customRepository')->andReturn(['repository' => 'git@gitlab.com:user/repo.git', 'port' => 22]);
    $application->shouldReceive('getAttribute')->with('private_key')->andReturn($privateKey);
    $application->shouldReceive('getAttribute')->with('settings')->andReturn($settings);
    $application->shouldReceive('getAttribute')->with('git_commit_sha')->andReturn('HEAD');

    // exec_in_docker = false → the loadComposeFile / host clone path
    $result = $application->generateGitImportCommands('test-deployment-uuid', 0, null, false);

    expect($result['commands'])
        ->toContain("tee {$keyPath}")
        ->toContain("-i {$keyPath} -o IdentitiesOnly=yes")
        ->toContain("trap 'rm -f {$keyPath}' EXIT")
        ->not->toContain('tee /root/.ssh/id_rsa >');
});

it('writes a GitLab source private key to a per-deployment path and cleans it up when cloning on the host', function () use ($keyPath) {
    $privateKey = Mockery::mock(PrivateKey::class)->makePartial();
    $privateKey->shouldReceive('getAttribute')->with('private_key')->andReturn('fake-private-key');

    $gitlabSource = Mockery::mock(GitlabApp::class)->makePartial();
    $gitlabSource->shouldReceive('getMorphClass')->andReturn(GitlabApp::class);
    $gitlabSource->shouldReceive('getAttribute')->with('html_url')->andReturn('https://gitlab.com');
    $gitlabSource->shouldReceive('getAttribute')->with('privateKey')->andReturn($privateKey);
    $gitlabSource->shouldReceive('getAttribute')->with('custom_port')->andReturn(22);

    $settings = Mockery::mock(ApplicationSetting::class)->makePartial();
    $settings->shouldReceive('getAttribute')->with('is_git_shallow_clone_enabled')->andReturn(false);
    $settings->shouldReceive('getAttribute')->with('is_git_submodules_enabled')->andReturn(false);
    $settings->shouldReceive('getAttribute')->with('is_git_lfs_enabled')->andReturn(false);

    $application = Mockery::mock(Application::class)->makePartial();
    $application->git_branch = 'main';
    $application->source = $gitlabSource;
    $application->shouldReceive('deploymentType')->andReturn('source');
    $application->shouldReceive('customRepository')->andReturn(['repository' => 'git@gitlab.com:user/repo.git', 'port' => 22]);
    $application->shouldReceive('getAttribute')->with('source')->andReturn($gitlabSource);
    $application->shouldReceive('getAttribute')->with('settings')->andReturn($settings);
    $application->shouldReceive('getAttribute')->with('git_commit_sha')->andReturn('HEAD');

    $result = $application->generateGitImportCommands('test-deployment-uuid', 0, null, false);

    expect($result['commands'])
        ->toContain("tee {$keyPath}")
        ->toContain("-i {$keyPath} -o IdentitiesOnly=yes")
        ->toContain("trap 'rm -f {$keyPath}' EXIT")
        ->not->toContain('tee /root/.ssh/id_rsa >');
});

it('uses the per-deployment deploy key for pull request fetches', function () use ($keyPath) {
    $privateKey = Mockery::mock(PrivateKey::class)->makePartial();
    $privateKey->shouldReceive('getAttribute')->with('private_key')->andReturn('fake-private-key');

    $settings = Mockery::mock(ApplicationSetting::class)->makePartial();
    $settings->shouldReceive('getAttribute')->with('is_git_shallow_clone_enabled')->andReturn(false);
    $settings->shouldReceive('getAttribute')->with('is_git_submodules_enabled')->andReturn(false);
    $settings->shouldReceive('getAttribute')->with('is_git_lfs_enabled')->andReturn(false);

    $application = Mockery::mock(Application::class)->makePartial();
    $application->git_branch = 'main';
    $application->shouldReceive('deploymentType')->andReturn('deploy_key');
    $application->shouldReceive('customRepository')->andReturn(['repository' => 'git@github.com:user/repo.git', 'port' => 22]);
    $application->shouldReceive('getAttribute')->with('private_key')->andReturn($privateKey);
    $application->shouldReceive('getAttribute')->with('settings')->andReturn($settings);
    $application->shouldReceive('getAttribute')->with('git_commit_sha')->andReturn('HEAD');

    $result = $application->generateGitImportCommands('test-deployment-uuid', 123, 'github', false);

    expect($result['commands'])
        ->toContain("GIT_SSH_COMMAND=\"ssh -o ConnectTimeout=30 -p 22 -o Port=22 -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i {$keyPath} -o IdentitiesOnly=yes\" git fetch origin pull/123/head:pr-123-coolify")
        ->not->toContain('GIT_SSH_COMMAND="ssh -o ConnectTimeout=30 -p 22 -o Port=22 -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i /root/.ssh/id_rsa" git fetch origin pull/123/head:pr-123-coolify');
});

it('does not force a missing per-deployment key for other repository pull request fetches', function () use ($keyPath) {
    $settings = Mockery::mock(ApplicationSetting::class)->makePartial();
    $settings->shouldReceive('getAttribute')->with('is_git_shallow_clone_enabled')->andReturn(false);
    $settings->shouldReceive('getAttribute')->with('is_git_submodules_enabled')->andReturn(false);
    $settings->shouldReceive('getAttribute')->with('is_git_lfs_enabled')->andReturn(false);

    $application = Mockery::mock(Application::class)->makePartial();
    $application->git_branch = 'main';
    $application->shouldReceive('deploymentType')->andReturn('other');
    $application->shouldReceive('customRepository')->andReturn(['repository' => 'git@github.com:user/repo.git', 'port' => 22]);
    $application->shouldReceive('getAttribute')->with('settings')->andReturn($settings);
    $application->shouldReceive('getAttribute')->with('git_commit_sha')->andReturn('HEAD');

    $result = $application->generateGitImportCommands('test-deployment-uuid', 123, 'github', false);

    expect($result['commands'])
        ->toContain('GIT_SSH_COMMAND="ssh -o ConnectTimeout=30 -p 22 -o Port=22 -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i /root/.ssh/id_rsa" git fetch origin pull/123/head:pr-123-coolify')
        ->not->toContain($keyPath);
});
